# Zwangsversteigerung Update Mailer

Schickt täglich eine E-Mail mit neuen od. geänderten Zwangsversteigerungen von `http://www.zvg-portal.de`.


## Setup

### Config

Datei `config.dist.php` umkopieren als `config.php` und anpassen.

- `web_host`: von Wo die Detail-HTML abgerufen werden können. 

### Cronjob

```
# m h  dom mon dow   command
0 6 * * * php /var/www/zwangsversteigerung/suche.php
```

### Nginx

Datei `/etc/nginx/sites-available/zwangsversteigerung`

```
server {
    listen 80;
    server_name zwangsversteigerung.pi.rh-flow.de;
    root /var/www/zwangsversteigerung;
}
```

