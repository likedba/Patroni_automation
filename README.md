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
- Ubuntu ISO uploaded to datastore path configured by `esxi_ubuntu_iso_path`.
- Ubuntu source is the live server ISO (no desktop UI by default).
- Unattended Ubuntu installation method available (e.g. autoinstall seed).
- For unattended install, attach a cloud-init seed ISO (`esxi_autoinstall_seed_iso_path`) built from `templates/autoinstall.user-data.j2` and `templates/autoinstall.meta-data.j2`.
- Vault connectivity for secrets referenced in `vars.yml`.
- The control node needs `vault` CLI installed and authenticated.
- `community.vmware` collection is mandatory; if Galaxy is unreachable, provide local tarball path via `-e community_vmware_collection_tarball=/path/community-vmware-*.tar.gz`.
- Vault secrets are read from `cubbyhole/secret` via `vault` CLI (`vault kv get -field=...`) using `VAULT_ADDR` and `VAULT_TOKEN` from the shell environment.
- If `aleksei_password_hash` is missing in Vault, playbook auto-generates SHA-512 hash from `aleksei_user_pass` and stores it back to `cubbyhole/secret`.
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

- The autoinstall template creates `bootstrap_user` (default `aleksei`) with sudo access.
