# D&JC Food Products

PHP + MySQL ordering / POS system. Deployed on **Render** (Docker) with **TiDB Cloud** as the database.

## Deploy

1. **TiDB Cloud** - create a cluster, click *Connect*, create a SQL user/password and note the
   host, port (4000), user (looks like `abc123.root`) and password. Create a database named `djc_db`.
2. **Import your existing schema + data** into TiDB (export `djc_db` from phpMyAdmin / InfinityFree as `.sql`,
   then import it with the TiDB Cloud *Import* page or `mysql --ssl-mode=VERIFY_IDENTITY ... < djc_db.sql`).
3. **Render** - New > Blueprint (or Web Service) > pick this repo. Render reads `render.yaml`.
   Fill in the environment variables:

| Variable | Value |
|---|---|
| `DB_HOST` | TiDB host, e.g. `gateway01.ap-southeast-1.prod.aws.tidbcloud.com` |
| `DB_PORT` | `4000` |
| `DB_USER` / `DB_PASSWORD` | from the TiDB Connect dialog |
| `DB_NAME` | `djc_db` |
| `DB_SSL` | `true` |
| `BREVO_API_KEY` / `MAIL_FROM_EMAIL` | optional - Brevo API key + a sender verified in Brevo (emails work on Render free) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | optional - Gmail SMTP instead (blocked on Render's free tier) |
| `PAYMONGO_SECRET_KEY` / `PAYMONGO_WEBHOOK_SECRET` | optional - turns PayMongo checkout on |

4. PayMongo webhook URL: `https://<your-app>.onrender.com/php/paymongo_webhook.php`

## Notes

- Secrets are **never** committed. The `php/private/*.sample.php` files are templates; the container writes the real
  config files from environment variables at startup (`docker/write-config.php`).
- Render's disk is ephemeral: images uploaded through the admin panel (`uploads/`) disappear on redeploy/restart
  unless you add a Render persistent disk (paid) or move uploads to object storage.
- Render's free web service sleeps after inactivity, so the first request can take ~1 minute.

## Local (XAMPP)

Copy `php/private/db_config.sample.php` to `php/private/db_config.php` and fill it in.
