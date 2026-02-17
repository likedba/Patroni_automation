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

- All deployment variables are centralized in `vars.yml`.
- Ansible control node with collections from `collections/requirements.yml`.
- Direct access to ESXi host and datastore.
- Prepared source VM (golden VM) exists on ESXi (`esxi_clone_source_vm` in `vars.yml`).
- Vault connectivity for secrets referenced in `vars.yml`.
- The control node needs `vault` CLI installed and authenticated.
- `community.vmware` collection is mandatory; if Galaxy is unreachable, provide local tarball path via `-e community_vmware_collection_tarball=/path/community-vmware-*.tar.gz`.
- Vault secrets are read from `secret/patroni_dpl` via `vault` CLI (`vault kv get -field=...`) using `VAULT_ADDR` and `VAULT_TOKEN` from the shell environment.
- Source VM should have `open-vm-tools`, `python3`, `sudo`, and SSH enabled for successful guest customization and Ansible access.
- Patroni inventory is built dynamically from `vars.yml` (`patroni*_hostname`, `patroni*_ip`) so no fixed Patroni IPs are stored in `inventories/production/hosts.yml`.
- `patroni*_mac` in `vars.yml` is optional; leave it empty to let ESXi assign a valid MAC automatically.

## Run

```bash
ansible-galaxy collection install -r collections/requirements.yml
ansible-playbook playbooks/prepare_vault_secrets.yml
ansible-playbook playbooks/deploy_patroni_cluster.yml
```

`playbooks/prepare_vault_secrets.yml` auto-creates missing Vault fields (only for DB/Patroni users):
- `admin_user_pass`, `replicator_user_pass`, `postgres_user_pass`, `rewind_user_pass`
- `app_user_pass`, `backup_user_pass`, `postgres_exporter_user_pass`, `zbx_monitor_user_pass`, `otel_user_pass`, `ppem_agent_user_pass`, `auditor_user_pass`

It does not auto-create infrastructure/bootstrap secrets like `esxi_pass`, `aleksei_user_pass`, or `aleksei_password_hash`.

## Notes

- `etcd` service is enabled on boot.
- `patroni` service is **not** enabled on boot (starts from playbook only).
- PostgreSQL data directory is `/pgdata/{{ pg_major_version }}/data`.

- VM deployment now uses clone-from-VM workflow from `esxi_clone_source_vm`.
- For standalone ESXi, set `esxi_guest_customization_enabled: false` (default). In this mode VMware guest customization is skipped because many ESXi-only setups return `The operation is not supported on the object`.
- With standalone mode, assign fixed `patroni*_mac` values and use DHCP reservations to map each node to the expected `patroni*_ip`.
- In standalone mode, source object should be a regular powered-off VM (golden VM), not a vCenter template object.
- Standalone clone path intentionally avoids extra VM reconfiguration during clone for maximum API compatibility.
