# Single Serving Secret Meme Image Text

Run locally to test with `php -S localhost:8006 -c php.ini`.

# Setup

- Create var, make sure is writable by the server
- When hosting, the SQLite DB file _and parent directory_ must be writable by the webserver user.
- Not sure how to pick up the `php.ini` w/my apache config, so enable the modules in php.ini in the global one on the server

Create the database as: (ie, while running `sqlite3 images.db`)

```
CREATE TABLE images (id STRING PRIMARY KEY NOT NULL, ext STRING NOT NULL, top STRING, bottom STRING, secret STRING, shown BOOL);
```
