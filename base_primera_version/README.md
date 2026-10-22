# First-version test database

Compressed SQLite database delivered with the package (the raw file is 181 MB, above the GitHub 100 MB limit).

Restore and verify:

```sh
gunzip -k local_test.sqlite.gz
shasum -a 256 -c local_test.sqlite.sha256
```

Use the restored file as the source for `php trasladar_a_mysql.php --origen=PATH/local_test.sqlite` (the destination MySQL must be empty).

This folder is excluded from the Docker image (`.dockerignore`). It contains application users and password hashes: keep the repository private.
