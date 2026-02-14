# Patroni automation for 3-node cluster on ESXi

## What this repository does

The playbook `playbooks/deploy_patroni_cluster.yml` automates:
1. Full re-creation of 3 Patroni VMs on direct ESXi host 8.0.3.
2. Ubuntu bootstrap and package update.
3. DNS registration in `dnsmasq` on `192.168.1.146`.
4. ETCD + Patroni + PostgreSQL installation and configuration.
5. ETCD cluster health check with table output and fail-fast logic.
6. Patroni cluster health check with table output and fail-fast logic.
7. DB object creation on active Patroni primary.

## Prerequisites

- Ansible control node with collections from `collections/requirements.yml`.
- Direct access to ESXi host and datastore.
- Ubuntu ISO uploaded to datastore path configured by `esxi_ubuntu_iso_path`.
- Unattended Ubuntu installation method available (e.g. autoinstall seed).
- Vault connectivity for secrets referenced in `vars.yml`.
- Patroni inventory is built dynamically from `vars.yml` (`patroni*_hostname`, `patroni*_ip`) so no fixed Patroni IPs are stored in `inventories/production/hosts.yml`.

## Run

```bash
ansible-galaxy collection install -r collections/requirements.yml
ansible-playbook playbooks/deploy_patroni_cluster.yml
```

## Notes

- `etcd` service is enabled on boot.
- `patroni` service is **not** enabled on boot (starts from playbook only).
- PostgreSQL data directory is `/pgdata/{{ pg_major_version }}/data`.
