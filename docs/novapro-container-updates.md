# NovaPro container updates

Every successful push to `AKolenda/solidtime`'s `main` branch publishes two Linux AMD64 images:

- `ghcr.io/akolenda/solidtime:latest`
- `ghcr.io/akolenda/solidtime:sha-<commit>`

The SHA tag provides a stable rollback target. `latest` advances only after the complete application and container build succeeds.

## One-time server setup

Keep the existing production Compose file and apply `docker-compose.novapro-image.yml` after it. Also continue applying `docker-compose.backups.yml` when database backups are enabled.

```bash
docker compose \
  -f <production-compose-file> \
  -f docker-compose.backups.yml \
  -f docker-compose.novapro-image.yml \
  config
```

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
  -f docker-compose.backups.yml \
  -f docker-compose.novapro-image.yml \
  pull app scheduler

docker compose \
  -f <production-compose-file> \
  -f docker-compose.backups.yml \
  -f docker-compose.novapro-image.yml \
  up -d app scheduler
```

## Pin or roll back

Set `SOLIDTIME_IMAGE` in the deployment environment to a published SHA tag, then run the update commands again:

```dotenv
SOLIDTIME_IMAGE=ghcr.io/akolenda/solidtime:sha-586293b
```

Remove that setting, or change it back to `ghcr.io/akolenda/solidtime:latest`, to resume normal updates.
