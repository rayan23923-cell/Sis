# SIS on AWS

One EC2 server (Graviton ARM, Ubuntu 24.04) running the same Docker stack as the VPS staging
(`deploy/vps`), plus its own Caddy for HTTPS. Made by one CloudFormation template; deployed from
GitHub Actions on every push to `main`.

| Item | Choice |
|---|---|
| Server | `t4g.medium` (2 vCPU, 4 GB, + 2 GB swap), 40 GB encrypted gp3 disk |
| Open ports | 80, 443 only. **No SSH**: use Systems Manager > Session Manager |
| Deploy access | GitHub OIDC role, `main` branch of this repo only, may only run commands on this server. No AWS keys stored anywhere |
| Backups | Database dump before every deploy (last 20, `/root/sis-backups`) + daily disk snapshot (last 7) |
| Hardening | IMDSv2 only, unattended security updates, least-privilege DB accounts (G6a) |
| Address | `https://<ip-with-dashes>.sslip.io` until a real domain is set (GitHub variable `SIS_DOMAIN`) |
| Cost (approx., eu-central-1) | ~ $25–35 / month (server, disk, public IPv4, snapshots) |

## Set up (once, about 10 minutes)

1. AWS console > **CloudFormation** > *Create stack* > *Upload a template file* > `deploy/aws/sis.yaml`.
   Stack name `sis`. Leave the defaults (set `CreateGitHubOidcProvider` to `false` only if IAM >
   Identity providers already lists `token.actions.githubusercontent.com`). Tick the IAM acknowledgement.
2. When the stack is `CREATE_COMPLETE`, open its **Outputs** and copy `AwsRoleArn`, `AwsInstanceId`,
   `AwsRegion` into GitHub > Settings > Secrets and variables > Actions > **Variables** as
   `AWS_ROLE_ARN`, `AWS_INSTANCE_ID`, `AWS_REGION` (they are not secrets).
3. GitHub > Actions > **Deploy to AWS** > *Run workflow*. The first run builds the images (~10 min).
   The site is the stack's `SiteUrl` output.

The administrator password is generated on the server, never printed. Read it with Session Manager:
`sudo cat /root/sis-admin-password.txt`.

## Own domain

Point an A record at the `SiteIp` output, add the GitHub variable `SIS_DOMAIN` (e.g. `sis.example.edu.iq`)
and run the workflow again. Caddy gets the certificate itself.

## Remove

Delete the stack. The disk is kept on purpose (`DeleteOnTermination: false`) so data is never lost by
accident; delete the `sis-data` volume and its snapshots in EC2 when it is really no longer needed.
