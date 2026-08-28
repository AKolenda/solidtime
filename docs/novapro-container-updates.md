# NovaPro container updates

Every successful push to `AKolenda/solidtime`'s `main` branch publishes two Linux AMD64 images:

- `ghcr.io/akolenda/solidtime:latest`
- `ghcr.io/akolenda/solidtime:sha-<commit>`

The SHA tag provides a stable rollback target. `latest` advances only after the complete application and container build succeeds.

## Set up the server once

Create the host backup folder and give Solidtime's container user access:

```bash
sudo install -d -o 1000 -g 1000 -m 0750 /mnt/solidtime_backups/solidtime_backups
```

Keep the existing production Compose file. Apply `docker-compose.novapro-image.yml` after it. This one override selects the NovaPro image and mounts the backup folder in both required containers.

```bash
docker compose \
  -f <production-compose-file> \
  -f docker-compose.novapro-image.yml \
  config
```

The override maps `${DATABASE_BACKUP_HOST_PATH:-/mnt/solidtime_backups}` on the host to `/backups` inside Solidtime. The Database Backups page defaults to `/backups/solidtime_backups`. Do not enter the host path in the browser.

To use a different host folder, set one value in the `.env` file beside the Compose files:

```dotenv
DATABASE_BACKUP_HOST_PATH=/your/host/backup/folder
```

No other backup path setting is required. Docker Compose reads this value for both the app and scheduler containers. The folder must already exist and be writable by container user 1000.

If the GHCR package is private, authenticate once using a GitHub personal access token with `read:packages` permission:

```bash
docker login ghcr.io -u AKolenda
```

The token is entered at Docker's password prompt. Do not store it in the Compose file.

## Update

Run the same Compose file set for both commands:

```bash
docker compose \
  -f <production-compose-file> \
  -f docker-compose.novapro-image.yml \
  pull app scheduler

docker compose \
  -f <production-compose-file> \
  -f docker-compose.novapro-image.yml \
  up -d app scheduler
```

## Pin or roll back

Set `SOLIDTIME_IMAGE` in the deployment environment to a published SHA tag, then run the update commands again:

```dotenv
SOLIDTIME_IMAGE=ghcr.io/akolenda/solidtime:sha-586293b
```

Remove that setting, or change it back to `ghcr.io/akolenda/solidtime:latest`, to resume normal updates.
