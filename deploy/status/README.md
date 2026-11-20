# status.piuskl.me: Uptime Kuma status page and alerts (course sections 8/10)

Needs the wildcard certificate from `deploy/tls/setup-zerossl.sh` first.

## Run

1. Cloudflare DNS: add `A  status  <VPS IP>`, DNS only (grey cloud).
2. `cd /var/www/shop && sudo bash deploy/status/setup-status.sh`
3. **Immediately** open https://status.piuskl.me and create the admin account.
4. Add monitors:
   - HTTP(s) – Keyword: `https://shop.piuskl.me/view/pages/index.php`, keyword `Maison`, 60 s interval, with **Certificate Expiry Notification** turned on
   - HTTP(s): `https://shop.piuskl.me/index.php/product/all` (checks PHP and MySQL respond)
   - DNS: `shop.piuskl.me` A record
5. Settings → Notifications: add Telegram or email (SMTP) and attach it to every monitor.
6. Status Pages → New: slug `shop`, add the monitors. Public URL: `https://status.piuskl.me/status/shop`.

A monitor running on the same VM can't report that the whole VM is down. To catch that, also add `https://shop.piuskl.me` to a free external monitor (e.g. UptimeRobot) or an Azure Monitor alert.

**Demo evidence:** the public status page, then stop Apache for a minute (`sudo systemctl stop apache2`) and show the alert arriving on your phone.

## Rollback

```bash
sudo a2dissite status.piuskl.me && sudo systemctl reload apache2
sudo docker compose -f /opt/uptime-kuma/docker-compose.yml down
```
