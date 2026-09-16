# Sizing

Memory = `maxConcurrent × memoryMb + 256 MB`  
CPU = `maxConcurrent × cpu`  
Scratch disk = `maxConcurrent × outputMb × 2` plus persistent workspaces.

| Node | Suggested defaults |
| ---- | ------------------ |
| 4 GB laptop / T1 compose | `COMPUTE_MAX_CONCURRENT=2`, cap `memoryMb=512`, `cpu=1.0`, `outputMb=50` |
| 16 GB compute node / T2 | `COMPUTE_MAX_CONCURRENT=8`, cap `memoryMb=2048`, `cpu=2.0`, `outputMb=200` |

PHP quotas (`COMPUTE_RUNS_*`, `COMPUTE_CONCURRENT`) sit **below** these
hard caps. The sidecar is the ceiling.
