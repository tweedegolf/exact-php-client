# exact-php-client

Fork from picqer/exact-php-client. See that repo for a full readme.

## Composer install
Installing this Exact client for PHP can be done through Composer.

```
composer require picqer/exact-php-client:dev-master
```

And make sure to add the repo:

1. Set up app at Exact App Center to retrieve credentials
2. Authorize the integration from your app
3. Parse callback and finish connection set up
4. Use the library to do stuff

Steps 1 - 3 are only required once on set up.

### Set up app at Exact App Center to retrieve credentials

Set up an App at the Exact App Center to retrieve your `Client ID` and `Client Secret`.
You will also need to set the correct `Callback URL` for the oAuth dance to work.

### Authorize the integration from your app

The code below is an example `authorize()` function.

```php
$connection = new \Picqer\Financials\Exact\Connection();
$connection->setRedirectUrl('CALLBACK_URL'); // Same as entered online in the App Center
$connection->setExactClientId('CLIENT_ID');
$connection->setExactClientSecret('CLIENT_SECRET');
$connection->redirectForAuthorization();
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


### About divisions (administrations)

By default the library will use the default administration of the user. This means that when the user switches administrations in Exact Online. The library will also start working with this administration.

### Use the library to do stuff (examples)

```php
// Optionally set administration, otherwise use the current administration of the user
$connection->setDivision(123456);

// Create a new account
$account = new Account($connection);
$account->AddressLine1 = $customer['address'];
$account->AddressLine2 = $customer['address2'];
$account->City = $customer['city'];
$account->Code = $customer['customerid'];
$account->Country = $customer['country'];
$account->IsSales = 'true';
$account->Name = $customer['name'];
$account->Postcode = $customer['zipcode'];
$account->Status = 'C';
$account->save();


// Add a product in Exact
$item = new Item($connection);
$item->Code = $productcode;
$item->CostPriceStandard = $costprice;
$item->Description = $name;
$item->IsSalesItem = true;
$item->SalesVatCode = 'VH';
$item->save();


// Retrieve an item
$item = new Item($connection);
$item->find(ID);

// List items
$item = new Item($connection);
$item->get();

// List items with filter (using a filter always returns a collection)
$item = new Item($connection);
$items = $item->filter("Code eq '$productcode'"); // Uses filters as described in Exact API docs (odata filters)

// Create new invoice with invoice lines
$items[] = [
	'Item'      => $itemId,
	'Quantity'  => $orderproduct['amount'],
	'UnitPrice' => $orderproduct['price']
];

$salesInvoice = new SalesInvoice($this->connection());
$salesInvoice->InvoiceTo = $customer_code;
$salesInvoice->OrderedBy = $customer_code;
$salesInvoice->YourRef = $orderId;
$salesInvoice->SalesInvoiceLines = $items;
```

## Connect to other Exact country than NL
Choose the right base URL according to [Exact developers guide](https://developers.exactonline.com/#Exact%20Online%20sites.html)

```php
<?php
$connection = new \Picqer\Financials\Exact\Connection();
$connection->setRedirectUrl('CALLBACK_URL');
$connection->setExactClientId('CLIENT_ID');
$connection->setExactClientSecret('CLIENT_SECRET');
$connection->setBaseUrl('https://start.exactonline.de');
```

Check [src/Picqer/Financials/Exact](src/Picqer/Financials/Exact) for all available entities.

## Webhooks
Managaging webhook subscriptions is possible through the [WebhookSubscription](src/Picqer/Financials/Exact/WebhookSubscription.php) entitiy.

For authenticating incoming webhook calls you can use the [Authenticatable](src/Picqer/Financials/Exact/Webhook/Authenticatable.php) trait.
Supply the authenticate method with the full JSON request and your Webhook secret supplied by Exact, it will return true or false.

## Troubleshooting
> 'Picqer\Financials\Exact\ApiException' with message 'Error 400: Please add a $select or a $top=1 statement to the query string.'

In specific instances, sadly not documented in the API documentation of Exact this is a requirement. Probably to prevent overflooding requests. What you have to do when encountering this error is adding a select or top. The select is used to provide a list of fields you want to extract, the $top=1 limits the results to one item.

Examples:

Return only the EntryID and FinancialYear.
```php
$test = new GeneralJournalEntry($connection);
var_dump($test->filter('', '', 'EntryID, FinancialYear'));
```

The $top=1 is added like this:
```php
$test = new GeneralJournalEntry($connection);
var_dump($test->filter('', '', '', ['$top'=> 1]));
```

### Authentication error

> 'Fatal error: Uncaught Exception: Could not connect to Exact: Client error:POST https://start.exactonline.nl/api/oauth2/token resulted in a 400 Bad Request response: Bad Request in /var/www/html/oauth_call_connect.php:61 Stack trace: #0 {main} thrown in /var/www/html/oauth_call_connect.php on line 61`'

This error occurs because the code you get in your redirect URL is only valid for one call. When you call the authentication-process again with a "used" code. You get this error. Make sure you use the provided code by Exact Online only once to get your access token.

## Code example
See for example: [example/example.php](example/example.php)

## TODO
- Current entities do not contain all available properties. Feel free to submit a PR with added or extended entities if you require them. Use the ```userscript.js``` in greasemonkey or tampermonkey to generate entities consistently and completely.