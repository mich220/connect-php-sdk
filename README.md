![Connect PHP SDK](./assets/connect-logo.png)

# Connect PHP SDK

[![Build Status](https://travis-ci.com/ingrammicro/connect-php-sdk.svg?branch=master)](https://travis-ci.com/ingrammicro/connect-php-sdk) [![Latest Stable Version](https://poser.pugx.org/apsconnect/connect-sdk/v/stable)](https://packagist.org/packages/apsconnect/connect-sdk) [![License](https://poser.pugx.org/apsconnect/connect-sdk/license)](https://packagist.org/packages/apsconnect/connect-sdk) [![codecov](https://codecov.io/gh/ingrammicro/connect-php-sdk/branch/master/graph/badge.svg)](https://codecov.io/gh/ingrammicro/connect-php-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/apsconnect/connect-sdk.svg?style=flat&branch=master)](https://packagist.org/packages/apsconnect/connect-sdk)
[![PHP Eye](https://img.shields.io/php-eye/apsconnect/connect-sdk.svg?style=flat&branch=master&label=PHP-Eye%20tested)](https://php-eye.com/package/apsconnect/connect-sdk)

## Getting Started
Connect PHP SDK allows an easy and fast integration with [Connect](http://connect.cloud.im/) fulfillment API. Thanks to it you can automate the fulfillment of orders generated by your products.

In order to use this library, please ensure that you have read first the documentation available on Connect knowladge base article located [here](http://help.vendor.connect.cloud.im/support/solutions/articles/43000030735-fulfillment-management-module), this one will provide you a great information on the rest api that this library implements.

## Class Features

This library may be consumed in your project in order to automate the fulfillment of requests, this class once imported into your project will allow you to:

- Connect to Connect using your api credentials
- List all requests, and even filter them:
    - for a Concrete product
    - for a concrete status
- Process each request and obtain full details of the request
- Modify for each request the activation parameters in order to:
    - Inquiry for changes
    - Store information into the fulfillment request
- Change the status of the requests from it's initial pending state to either inquiring, failed or approved.
- Generate logs
- Collect debug logs in case of failure

Your code may use any scheduler to execute, from a simple cron to a cloud scheduler like the ones available in Azure, Google, Amazon or other cloud platforms.

## Installation & loading
Connect PHP SDK is available on [Packagist](https://packagist.org/packages/apsconnect/connect-sdk) (using semantic versioning), and installation via [Composer](https://getcomposer.org) is the recommended way to install Connect PHP SDK. Just add this line to your `composer.json` file:

```json
{
  "require": {
    "apsconnect/connect-sdk": "^14.0"
    }
}
```

or run

```sh
composer require apsconnect/connect-sdk --no-dev --prefer-dist --classmap-authoritative
```

Note that the `vendor` folder and the `vendor/autoload.php` script are generated by Composer

## A Simple Example

```php
<?php

require_once "vendor/autoload.php";

/**
 * Class ProductRequests
 */
class ProductRequests extends \Connect\FulfillmentAutomation
{
    

    /**
     * @param \Connect\Request $request
     * @return string|void
     * @return \Connect\ActivationTemplateResponse
     * @return \Connect\ActivationTileResponse  
     * @throws Exception
     * @throws \Connect\Exception
     * @throws \Connect\Fail
     * @throws \Connect\Skip
     * @throws \Connect\Inquire   
     */
    
    public function processRequest($request)
    {
        $this->logger->info("Processing Request: " . $request->id . " for asset: " . $request->asset->id);
        switch ($request->type) {
            case "purchase":
                if($request->asset->params['email']->value == ""){
                    throw new \Connect\Inquire(array(
                        $request->asset->params['email']->error("Email address has not been provided, please provide one")
                    ));
                }
                foreach ($request->asset->items as $item) {
                    if ($item->quantity > 1000000) {
                        $this->logger->info("Is Not possible to purchase product " . $item->id . " more than 1000000 time, requested: " . $item->quantity);
                        throw new \Connect\Fail("Is Not possible to purchase product " . $item->id . " more than 1000000 time, requested: " . $item->quantity);
                    }
                    else {
                        //Do some provisoning operation
                        //Update the parameters to store data
                        $paramsUpdate[] = new \Connect\Param('ActivationKey', 'somevalue');
                        //We may use a template defined on vendor portal as activation response, this will be what customer sees on panel
                        return new \Connect\ActivationTemplateResponse("TL-497-535-242");
                        // We may use arbitrary output to be returned as approval, this will be seen on customer panel. Please see that output must be in markup format
                        return new \Connect\ActivationTileResponse('\n# Welcome to Fallball!\n\nYes, you decided to have an account in our amazing service!\n\n');
                        // If we return empty, is approved with default message
                        return;
                    }
                }
            case "cancel":
                //Handle cancellation request
            case "change":
                //Handle change request
            default:
                throw new \Connect\Fail("Operation not supported:".$request->type);
        }
    }
}

//Main Code Block
try {
    $apiConfig = new \Connect\Config([
        'apiKey' => 'Key_Available_in_ui',
        'apiEndpoint' => 'https://api.connect.cloud.im/public/v1',
        'products' => 'CN-631-322-641' #Optional value
    ]);
    $requests = new ProductRequests($apiConfig);
    $requests->process();
    
} catch (Exception $e) {
    print "Error processing requests:" . $e->getMessage();
}
```
