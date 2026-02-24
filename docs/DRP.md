# Disaster Recovery Plan (DRP)

Процедуры аварийного восстановления для всех хостов инфраструктуры.

---

## Карта инфраструктуры

| Хост | IP | Тип | Сервисы | Данные |
|------|----|-----|---------|--------|
| vmpatronidb-1 | 192.168.1.135 | Stateful | PostgreSQL 18 PRIMARY, Patroni, etcd | `/pgdata/18/data` |
| vmpatronidb-2 | 192.168.1.136 | Stateful | PostgreSQL 18 STANDBY, Patroni, etcd | `/pgdata/18/data` |
| vmpatronidb-3 | 192.168.1.137 | Stateful | PostgreSQL 18 STANDBY, Patroni, etcd | `/pgdata/18/data` |
| vmubuntu-1 | 192.168.1.141 | Stateless | nginx reverse proxy + SSL | — |
| vmubuntu-2 | 192.168.1.142 | Backup replica | Реплика бэкапов vmubuntu-5 | `/backup/postgres`, `/srv/nfs/wordpress/uploads` |
| vmubuntu-3 | 192.168.1.143 | Stateless | WordPress + PHP-FPM + NFS client | — |
| vmubuntu-4 | 192.168.1.144 | Stateless | WordPress + PHP-FPM + NFS client | — |
| vmubuntu-5 | 192.168.1.145 | Stateful | NFS server + pg_probackup | `/backup/postgres`, `/srv/nfs/wordpress/uploads` |
| vmubuntu-6 | 192.168.1.146 | Stateful | Ansible, Vault, DNS (dnsmasq) | Vault store, dnsmasq config |
| vmubuntu-7 | 192.168.1.147 | Semi-stateful | ELK stack (Docker Compose) | Elasticsearch indices (потеря допустима) |
| vmubuntu-8 | 192.168.1.148 | Stateful | Zabbix Server, Grafana, PostgreSQL 18 | `/var/lib/postgresql/18/main` |

---

## Приоритет восстановления (при полной катастрофе)

```
1. vmubuntu-6  — из VM snapshot (Vault + DNS — без них ничего не работает)
2. vmubuntu-5  — NFS + бэкапы (данные восстанавливаются с vmubuntu-2)
3. vmubuntu-2  — реплика бэкапов (если vmubuntu-5 жив — просто пересоздать)
4. vmpatronidb-1/2/3 — PostgreSQL кластер (restore из pg_probackup)
5. vmubuntu-8  — мониторинг (restore БД из pg_probackup)
6. vmubuntu-3/4 — WordPress backends
7. vmubuntu-1  — nginx frontend
8. vmubuntu-7  — ELK (логи не критичны)
```

---

## Зависимости

```
vmubuntu-6 (Vault + DNS)
  └── Все остальные хосты (Vault lookups для паролей, DNS для *.infra.local)

vmubuntu-5 (NFS + Backups)
  ├── vmubuntu-3/4 (NFS mount для WordPress uploads)
  ├── vmpatronidb-1/2/3 (pg_probackup restore при полной потере)
  └── vmubuntu-8 (pg_probackup restore для Zabbix/Grafana БД)

vmpatronidb-1/2/3 (Patroni cluster)
  ├── vmubuntu-3/4 (WordPress DB connection)
  └── Все хосты (Zabbix monitoring data — не критично)
```

---

## DRP-1: vmpatronidb-1/2/3 (Patroni cluster)

### Сценарий А: Потеря одной ноды (кворум сохранён)

**Условие**: 2 из 3 нод работают. Кластер продолжает обслуживать запросы.
**Downtime WordPress**: нет (Patroni failover автоматический).

```bash
# 1. Пересоздать VM из шаблона
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmpatronidb-X"

# 2. Установить пакеты
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags packages \
  --limit vmpatronidb-X

# 3. Удалить мёртвый member из etcd (на ЖИВОЙ ноде):
ssh vmpatronidb-Y
etcdctl member list
etcdctl member remove <MEMBER_ID>
etcdctl member add etcd_vmpatronidb-X \
  --peer-urls=http://192.168.1.X:2380

# 4. Запустить etcd + patroni (нода присоединится к кластеру)
# patroni_setup автоматически ставит ETCD_INITIAL_CLUSTER_STATE=existing
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags etcd,patroni \
  --limit vmpatronidb-X

# Patroni автоматически выполнит pg_basebackup с primary

# 5. Установить агент мониторинга + Filebeat
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 \
  --limit vmpatronidb-X
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat \
  --limit vmpatronidb-X
```

**Верификация:**
```bash
ssh vmpatronidb-Y 'patronictl -c /etc/patroni/config.yml list'
# Ожидаем: 3 ноды — 1 Leader + 2 Replica, все running/streaming

ssh vmpatronidb-Y 'etcdctl endpoint status -w table \
  --endpoints=192.168.1.135:2379,192.168.1.136:2379,192.168.1.137:2379'
```

---

### Сценарий Б: Потеря PRIMARY (кворум сохранён)

**Условие**: primary упал, Patroni автоматически промотирует одну из реплик.
**Downtime WordPress**: ~30 секунд (автоматический failover).

Процедура восстановления бывшего primary — та же, что в Сценарии А.
Бывший primary присоединится как реплика.

---

### Сценарий В: Потеря ВСЕХ 3 нод (полная потеря кластера)

**Условие**: Все данные потеряны. Восстановление из pg_probackup.
**Downtime WordPress**: до завершения восстановления.

```bash
# 1. Пересоздать все 3 VM
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision

# 2. Установить пакеты
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags packages

# 3. Настроить etcd (НОВЫЙ кластер)
# ВАЖНО: шаблон etcd.j2 содержит ETCD_INITIAL_CLUSTER_STATE="new"
# Роль patroni_setup изменит на "existing" после первого запуска
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags etcd

# 4. Восстановить БД из бэкапа на vmpatronidb-1 (будущий primary)
ssh vmubuntu-5
sudo -u postgres pg_probackup-18 show \
  -B /backup/postgres --instance=patroni
# Убедиться что бэкап есть и статус OK

sudo -u postgres pg_probackup-18 restore \
  -B /backup/postgres \
  --instance=patroni \
  -D /pgdata/18/data \
  --remote-host=192.168.1.135 \
  --remote-user=postgres \
  --recovery-target=latest

# 5. Запустить Patroni на vmpatronidb-1 ПЕРВЫМ (станет primary)
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags patroni \
  --limit vmpatronidb-1

# 6. Подождать 30 секунд, затем запустить реплики
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags patroni \
  --limit vmpatronidb-2,vmpatronidb-3

# 7. Применить конфигурацию БД (юзеры, pg_hba, DCS)
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags db

# 8. Агенты + логирование
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 \
  --limit patroni_nodes
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat \
  --limit patroni_nodes
```

**Верификация:**
```bash
ssh vmpatronidb-1 'patronictl -c /etc/patroni/config.yml list'
ssh vmpatronidb-1 'sudo -u postgres psql -c "SELECT datname FROM pg_database;"'
# Ожидаем: wordpress_db, postgres

ssh vmpatronidb-1 'sudo -u postgres psql -d wordpress_db \
  -c "SELECT count(*) FROM wp_posts;"'
```

---

## DRP-2: vmubuntu-5 (NFS + Backup server)

**Критичность**: ВЫСОКАЯ — содержит все бэкапы БД и WordPress uploads.
**Защита**: rsync-репликация на vmubuntu-2 (каждые 4 часа).

```bash
# 1. Пересоздать VM из шаблона
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-5"

# 2. Восстановить данные с vmubuntu-2 (реплика)
# ВАЖНО: выполняется С vmubuntu-2 (у неё есть SSH ключ)
ssh vmubuntu-2
sudo rsync -az --progress \
  /backup/postgres/ \
  aleksei@192.168.1.145:/backup/postgres/

sudo rsync -az --progress \
  /srv/nfs/wordpress/uploads/ \
  aleksei@192.168.1.145:/srv/nfs/wordpress/uploads/

# 3. Развернуть NFS сервер
ansible-playbook playbooks/deploy_wordpress.yml --tags nfs

# 4. Развернуть pg_probackup (инициализация, SSH ключи, cron)
ansible-playbook playbooks/deploy_backup.yml

# 5. Установить агент + Filebeat
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 \
  --limit vmubuntu-5
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat \
  --limit vmubuntu-5
```

**Верификация:**
```bash
# NFS работает
ssh vmubuntu-5 'showmount -e localhost'

# pg_probackup видит инстансы
ssh vmubuntu-5 'sudo -u postgres pg_probackup-18 show -B /backup/postgres'

# WordPress бэкенды примонтировали NFS
ssh vmubuntu-3 'df -h | grep nfs'
ssh vmubuntu-4 'df -h | grep nfs'

# Репликация на vmubuntu-2 настроена
ssh vmubuntu-5 'crontab -l -u aleksei'
```

---

## DRP-3: vmubuntu-8 (Monitoring: Zabbix + Grafana + PostgreSQL)

**Критичность**: СРЕДНЯЯ — мониторинг можно временно потерять.
**Данные**: PostgreSQL с БД `zabbix` и `grafana` — бэкапируются pg_probackup.

```bash
# 1. Пересоздать VM
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-8"

# 2. Развернуть PostgreSQL (создаст пустой кластер)
ansible-playbook playbooks/deploy_monitoring.yml --tags postgres

# 3. Остановить PostgreSQL перед восстановлением
ssh vmubuntu-8 'sudo systemctl stop postgresql'

# 4. Очистить каталог данных (pg_probackup restore требует пустой каталог)
ssh vmubuntu-8 'sudo rm -rf /var/lib/postgresql/18/main/*'

# 5. Восстановить БД из pg_probackup (с vmubuntu-5)
ssh vmubuntu-5
sudo -u postgres pg_probackup-18 show \
  -B /backup/postgres --instance=monitoring
# Убедиться что бэкап есть

sudo -u postgres pg_probackup-18 restore \
  -B /backup/postgres \
  --instance=monitoring \
  -D /var/lib/postgresql/18/main \
  --remote-host=192.168.1.148 \
  --remote-user=postgres \
  --recovery-target=latest

# 6. Запустить PostgreSQL
ssh vmubuntu-8 'sudo systemctl start postgresql'

# 7. Развернуть Zabbix Server + Grafana
ansible-playbook playbooks/deploy_monitoring.yml --tags zabbix,grafana

# 8. Переустановить агенты на всех хостах + перерегистрировать в Zabbix
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2,register

# 9. Filebeat
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat \
  --limit vmubuntu-8
```

**Верификация:**
```bash
# Zabbix API отвечает
curl -ks https://192.168.1.148/api_jsonrpc.php \
  -d '{"jsonrpc":"2.0","method":"apiinfo.version","id":1,"params":{}}' \
  -H "Content-Type: application/json"

# Grafana работает
curl -ks https://192.168.1.148:3000/api/health

# БД восстановлены
ssh vmubuntu-8 'sudo -u postgres psql \
  -c "SELECT datname FROM pg_database WHERE datname IN ('"'"'zabbix'"'"','"'"'grafana'"'"');"'
```

---

## DRP-4: vmubuntu-7 (ELK Stack)

**Критичность**: НИЗКАЯ — логи можно потерять.
**Данные**: Elasticsearch индексы — допустимая потеря.

```bash
# 1. Пересоздать VM
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-7"

# 2. Установить Docker
ansible-playbook playbooks/deploy_logging.yml --tags docker --limit vmubuntu-7

# 3. Развернуть ELK stack (ES + Logstash + Kibana + дашборды)
ansible-playbook playbooks/deploy_logging.yml --tags elk

# 4. Filebeat на vmubuntu-7
ansible-playbook playbooks/deploy_logging.yml --tags filebeat --limit vmubuntu-7

# 5. Агент мониторинга
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 --limit vmubuntu-7
```

**Верификация:**
```bash
# Elasticsearch cluster health
curl -s http://192.168.1.147:9200/_cluster/health | python3 -m json.tool

# Kibana status
curl -s http://192.168.1.147:5601/api/status -H "kbn-xsrf: true" | \
  python3 -c "import sys,json; print(json.load(sys.stdin)['status']['overall']['level'])"

# Дашборды и saved searches
curl -s "http://192.168.1.147:5601/api/saved_objects/_find?type=dashboard" \
  -H "kbn-xsrf: true" | python3 -c "import sys,json; print(f'Dashboards: {json.load(sys.stdin)[\"total\"]}')"
```

**Примечание**: исторические логи будут потеряны. Elasticsearch начнёт накапливать данные заново. Дашборды и saved searches восстанавливаем через kibana_bootstrap.sh.

---

## DRP-5: vmubuntu-1 (nginx frontend)

**Критичность**: ВЫСОКАЯ для пользователей (точка входа), но STATELESS.
**Данные**: нет — полностью пересоздаётся из плейбука.
**Downtime WordPress**: до завершения восстановления.

```bash
# 1. Пересоздать VM
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-1"

# 2. Развернуть nginx frontend (reverse proxy + SSL)
ansible-playbook playbooks/deploy_wordpress.yml --tags frontend

# 3. Агент + Filebeat
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 --limit vmubuntu-1
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat --limit vmubuntu-1
```

**Верификация:**
```bash
# HTTPS отвечает
curl -ks https://192.168.1.141/ -o /dev/null -w "%{http_code}\n"
# Ожидаем: 200 или 302

# HTTP→HTTPS redirect
curl -s http://192.168.1.141/ -o /dev/null -w "%{http_code}\n"
# Ожидаем: 301
```

---

## DRP-6: vmubuntu-3 / vmubuntu-4 (WordPress backends)

**Критичность**: СРЕДНЯЯ (второй backend продолжает работать).
**Данные**: нет — WordPress код пересоздаётся, данные в Patroni DB + NFS.

```bash
# 1. Пересоздать VM (замените X на 3 или 4)
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-X"

# 2. Развернуть WordPress backend (+ NFS mount + pg4wp + wp-config)
ansible-playbook playbooks/deploy_wordpress.yml --tags backends \
  --limit vmubuntu-X

# 3. Агент + Filebeat
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 --limit vmubuntu-X
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat --limit vmubuntu-X
```

**Верификация:**
```bash
# NFS примонтирован
ssh vmubuntu-X 'df -h | grep nfs'

# WordPress отвечает через PHP-FPM
ssh vmubuntu-X 'curl -s http://127.0.0.1:8080/ -o /dev/null -w "%{http_code}\n"'
# Ожидаем: 200 или 302

# WordPress подключается к БД
ssh vmubuntu-X 'curl -s http://127.0.0.1:8080/wp-login.php -o /dev/null -w "%{http_code}\n"'
```

---

## DRP-7: vmubuntu-2 (Backup replica)

**Критичность**: НИЗКАЯ — потеря реплики не влияет на работу.
**Данные**: копия данных с vmubuntu-5 (восстанавливается rsync-ом).

```bash
# 1. Пересоздать VM
ansible-playbook playbooks/deploy_patroni_cluster.yml --tags provision \
  -e "target_vm=vmubuntu-2"

# 2. Настроить репликацию заново
ansible-playbook playbooks/deploy_backup.yml --tags backup_replication

# 3. Агент + Filebeat
ansible-playbook playbooks/deploy_monitoring.yml --tags agent2 --limit vmubuntu-2
ansible-playbook playbooks/deploy_logging.yml --tags docker,filebeat --limit vmubuntu-2

# 4. Первая синхронизация (запускается автоматически ролью,
#    но можно запустить вручную)
ssh vmubuntu-5 'sudo -u aleksei /backup/sync_to_replica.sh'
```

**Верификация:**
```bash
ssh vmubuntu-2 'sudo ls -la /backup/postgres/backups/'
ssh vmubuntu-2 'sudo ls -la /srv/nfs/wordpress/uploads/'
ssh vmubuntu-5 'crontab -l -u aleksei | grep sync'
```

---

## DRP-8: vmubuntu-6 (Control node) — справка

**Восстановление**: из VM snapshot в vCenter.

Критически важные компоненты:
- **HashiCorp Vault** — все секреты инфраструктуры
- **dnsmasq** — DNS-резолвер для `*.infra.local`
- **Ansible** — playbooks (в git, восстанавливаются через `git clone`)

После восстановления из snapshot:
```bash
# Проверить Vault
vault status
vault kv list secret/patroni_dpl/

# Проверить DNS
dig vmpatronidb-1.infra.local @127.0.0.1

# Обновить playbooks из git
cd ~/Patroni_automation && git pull
```

---

## Расписание бэкапов

| Что | Когда | Хранение |
|-----|-------|----------|
| pg_probackup FULL (patroni) | Суббота 01:00 | 2 полных + 7 дней |
| pg_probackup DELTA (patroni) | Ежедневно 08:00 (кроме Сб) | Привязаны к FULL |
| pg_probackup FULL (monitoring) | Суббота 01:00 | 2 полных + 7 дней |
| pg_probackup DELTA (monitoring) | Ежедневно 01:00 (кроме Сб) | Привязаны к FULL |
| rsync → vmubuntu-2 | Каждые 4 часа | Зеркало (--delete) |
| VM snapshot vmubuntu-6 | Вручную (рекомендуется еженедельно) | В vCenter |

---

