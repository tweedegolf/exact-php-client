# exact-php-client

Fork from picqer/exact-php-client. See that repo for a full readme.

## Composer install
Installing this Exact client for PHP can be done through Composer.

```
composer require picqer/exact-php-client:dev-master
```

And make sure to add the repo:

```
"repositories": [
		...,
		{
			"type": "git",
			"url":  "https://github.com/tweedegolf/exact-php-client.git"
		}
],
```

## Changes with respect to picqer/exact-php-client
+ Added 'Status' and 'StatusDescription' to fillable fields of PurchaseEntry.
+ Added 'StatusDescription' to fillable fields of SalesEntry.
