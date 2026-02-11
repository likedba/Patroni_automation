#
export PGDATA="/pgdata/{{ pg_major_version }}/data"
export ETCDCTL_API="3"
export PATRONI_SCOPE="{{ project_name }}_cluster"
etcd_node1={{ host1_ip }}
etcd_node2={{ host2_ip }}
etcd_node3={{ host3_ip }}
ENDPOINTS=$etcd_node1:2379,$etcd_node2:2379,$etcd_node3:2379
