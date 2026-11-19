# Deploying shop.piuskl.me with Apache2

Target: an Ubuntu/Debian server running Apache2, PHP 8.3 and MySQL/MariaDB,
with DNS for `shop.piuskl.me` already pointing at the server.

## 1. Where things go

| What | Path on server |
|---|---|
| Vhost config | `/etc/apache2/sites-available/shop.piuskl.me.conf` |
| Site code (this repo) | `/var/www/shop.piuskl.me/` |
| TLS certificate (Let's Encrypt) | `/etc/letsencrypt/live/shop.piuskl.me/fullchain.pem` |
| TLS private key (Let's Encrypt) | `/etc/letsencrypt/live/shop.piuskl.me/privkey.pem` |
| Logs | `/var/log/apache2/shop.piuskl.me-{access,error}.log` |

## 2. Install packages

```bash
sudo apt update
sudo apt install apache2 php8.3 libapache2-mod-php8.3 php8.3-mysql mysql-server certbot python3-certbot-apache
sudo a2enmod ssl headers
```

## 3. Put the code in place and set permissions

```bash
sudo git clone https://github.com/kakeetopius/maison-delamance.git /var/www/shop.piuskl.me

# Owner root, group www-data (the user Apache runs as)
sudo chown -R root:www-data /var/www/shop.piuskl.me

# Directories 750: root full access, Apache can enter/read, everyone else nothing
sudo find /var/www/shop.piuskl.me -type d -exec chmod 750 {} \;

# Files 640: root can edit, Apache can only read, everyone else nothing
sudo find /var/www/shop.piuskl.me -type f -exec chmod 640 {} \;
```

Why: Apache only needs to **read** the code. If an attacker ever got PHP code
execution as `www-data`, they still couldn't modify the site files. Nothing in
this app writes to disk (sessions go to PHP's own session dir), so no folder
needs to be writable.

## 4. Database

```bash
sudo mysql < /var/www/shop.piuskl.me/misc/ecommerce.sql
sudo mysql -e "CREATE USER 'eUser'@'localhost' IDENTIFIED BY 'CHANGE_ME'; \
  GRANT SELECT, INSERT, UPDATE, DELETE ON ecommerce.* TO 'eUser'@'localhost'; FLUSH PRIVILEGES;"
```

Then edit `/var/www/shop.piuskl.me/config/config.json`:
- `"host"`: change `"db"` (the Docker container name) to `"localhost"`
- `"pass"`: the new password you set above

`config.json` is blocked from the web by the vhost, and is `640 root:www-data`
so only root and Apache can read it.

## 5. Enable the site and get the certificate

```bash
sudo cp deploy/apache/shop.piuskl.me.conf /etc/apache2/sites-available/
sudo certbot certonly --apache -d shop.piuskl.me   # creates the cert + key
sudo a2ensite shop.piuskl.me
sudo apachectl configtest                          # must print "Syntax OK"
sudo systemctl reload apache2
```

Certbot sets the private key to `0600 root:root` itself and installs a timer
that renews the certificate automatically (`sudo certbot renew --dry-run` to test).

If you are using a bought certificate instead:
```bash
sudo cp shop.piuskl.me.crt /etc/ssl/certs/   && sudo chmod 644 /etc/ssl/certs/shop.piuskl.me.crt
sudo cp shop.piuskl.me.key /etc/ssl/private/ && sudo chmod 600 /etc/ssl/private/shop.piuskl.me.key
sudo chown root:root /etc/ssl/certs/shop.piuskl.me.crt /etc/ssl/private/shop.piuskl.me.key
```
and switch the two `SSLCertificate*` lines in the config.

## 6. Check it works

```bash
curl -I http://shop.piuskl.me                        # 301 -> https
curl -I https://shop.piuskl.me                       # 302 -> /view/pages/index.php
curl https://shop.piuskl.me/index.php/product/all    # JSON product list
curl -I https://shop.piuskl.me/config/config.json    # must be 403 Forbidden
curl -I https://shop.piuskl.me/docker-compose.yml    # must be 403 Forbidden
```

---

## How the config works (for questions)

**Two virtual hosts.** Apache picks a vhost by port and by the `Host` header
matching `ServerName`.
- `*:80` only redirects to HTTPS with a `301 permanent`, so nobody uses the site unencrypted.
- `*:443` serves the site.

**TLS.**
- `SSLEngine on` turns on HTTPS for this vhost.
- `SSLCertificateFile` is the public certificate. `fullchain.pem` also includes
  the intermediate CA, so browsers can verify the chain.
- `SSLCertificateKeyFile` is the private key. It must stay secret, which is why
  it's `600` and owned by root. Apache reads it as root at startup, before
  dropping to `www-data`.
- `SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1` allows only TLS 1.2 and 1.3. The older versions have known attacks.

**Security headers.**
- `Strict-Transport-Security` (HSTS) tells browsers to use HTTPS for this domain
  for a year, which blocks SSL-stripping attacks.
- `X-Content-Type-Options: nosniff` stops the browser guessing file types, which
  prevents some XSS tricks.
- `X-Frame-Options: SAMEORIGIN` stops other sites putting ours in an iframe (clickjacking).
- `Referrer-Policy` limits how much of our URL leaks to other sites.

**Directory rules.**
- `Options -Indexes`: if a folder has no index file, Apache won't list its contents.
- `AllowOverride None`: `.htaccess` files are ignored. That's faster, and all the
  rules live in one place.
- `AcceptPathInfo On`: the JavaScript calls URLs like `/index.php/cart/get`.
  Apache runs `index.php` and passes `/cart/get` along, and `index.php` splits
  `REQUEST_URI` to route the request.
- `DirectoryMatch ... Require all denied`: `config/`, `includes/`, `modal/`,
  `control/` and `misc/` are backend code, the DB config and the SQL dump. PHP
  loads them from disk with `require_once`, so they still work, but a browser
  requesting them gets **403 Forbidden**. Without this rule, anyone could download
  `config/config.json` and read the database password.
- `FilesMatch`: blocks hidden files like `.git` and the repo files. That matters
  most for `docker-compose.yml`, which contains the MySQL root password.

**Logs.** The site gets its own access and error logs, so it's easy to debug
separately from anything else on the server.
