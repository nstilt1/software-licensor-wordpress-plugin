# Software Licensor WordPress Plugin

## Setup/usage

Ensure that your website is running PHP 8 or later. This plugin is confirmed to be working with PHP 8.3.

1. Clone this repo.
2. Zip `software-licensor-wp-plugin`
3. Upload the plugin to your website.
4. Navigate from the admin dashboard to `WooCommerce>Settings>Integration>Software Licensor`.
5. Fill out the form and click save. If the form is missing data, the request might not go through and you won't be able to see a store ID in the next step.
6. Navigate from the admin dashboard to `Software Licensor>Software Licensor`. Verify that you can see a roughly 64-character long string at the top under `Store ID`. You will need to include this `store_id` in the client side code.
7. Navigate to `Software Licensor>Create/Update Licensed Product`. Fill out the form fields. Notice the `Allow Offline` checkbox. Offline license activations are not currently supported, but there is more info about them in the `Things not yet implemented in this WordPress Plugin` section. For now, I would recommend setting it to disallow offline licenses, as this setting can be easily changed by pasting the product ID into the `Product ID/prefix` field, and copying or updating the version in the `version` field.
8. Navigate to `Software Licensor>Software Licensor`, then copy the `Product ID` and the `Product Public Key`. The `Product Public Key` will need to be added to the client side code, and then there will need to be some attributes added to the product under `Products`:
9. Navigate to the Edit Product Page, under attributes, we need to add an attribute called `software_licensor_id` set to the `Product's ID`. Then we'll need an attribute called `license_type` that is set to the license type, such as `perpetual` or `trial`.
10. Add a new `page`, then insert the following `shortcode`: `[software_licensor_licenses_page]`. This page will now show the user's license information. They can click on a row and it will reveal the active machines. This list can update every 4 hours, or when `regenerate_license` is called, or whenever a new license is purchased. This page can also be styled. Simply hit `inspect element`, then use the class names to style the page. You can also enable some pagination by using the `data-index` field on the `tr`s.

### Creating products with different variations

It is possible to create `variable products` that are compatible with this WordPress plugin.

1. In the WordPress dashboard, navigate to `Products>Attributes`. Add a new attribute called `License Type` with this as the slug: `license_type`.
2. On the right side of the page on the `License Type` row, select `Configure terms`.
3. Add your desired terms. For now, only `Trial` and `Perpetual` licenses are supported. To add these terms, we can add the following:

* Trial term:
  * Name: `Trial`
  * Slug: `trial`
  * Description: `A [N] day trial for this software. The trial begins as soon as this license has been activated on your machine.` where `[N]` is the `Trial expiration days` that you have set for your store.
* Perpetual term:
  * Name: `Perpetual`
  * Slug: `perpetual`
  * Description: `A perpetual license for this software.`

4. Select your product under `Products>All Products`. Select `Attributes`, then `Add Existing`, and select `License Type`.
5. Ensure that `visible on the product page` and `used for variations` are both selected.
6. This should automatically change your product's type from `Simple Product` to `Variable Product`. If not, change your product's type next to `Product data -` at the top left of the product's settings area.
7. Select `Variations`, then select `Generate variations`, and ensure that all of your license types are showing on this page.
8. Choose `Save changes`, and now your product has variations!

## Example styles and scripts for the `[software_licensor_licenses_page]`
```html
<style>
#clipboard-notification-container {
  background-color: #5bff6c;
  transition-duration: 0.8s;
  width: 100%;
  justify-content: center;
  align-items: center;
  text-align: center;
  border-radius: 10px;
}

#clipboard-notication {
  text-align: center;
}

.licenses {
  width: 100%;
}
.SL-license-code-header {
  font-size: 20px;
  padding-bottom: 0.25em;
}

.SL-license-code-container {
  margin-bottom: 1em;
}
  
.SL-license-code {
  font-size: 16px;
  background-color: #f3f3f3f3;
  cursor: pointer;
  padding: 2px;
  border: 1px solid #ccc;
}

.SL-licenses-table {
  width: 100%;
  text-align: left;
  border: 1px solid;
  border-collapse: collapse;
  height: auto;
}

.SL-licenses-table>tbody>tr:nth-child(odd) {
  cursor: pointer;
}

.machine-table {
  border: 1px solid;
  border-collapse: collapse;
  display: table-row;
  width: 100%;
}

.SL-buttons-container {
  margin-top: 5px;
  margin-bottom: 5px;
}

.SL-regenerate-license-button {
  border: 1px solid #666666;
  border-radius: 4px;
  text-align: center;
  line-height: 1.2;
  background: #666666;
  color: #ffffff;
  font-size: 12px;
  letter-spacing: 1px;
  outline: none;
  display: flex;
  flex-direction: row;
  cursor: pointer;
  padding: 16px 24px;
}
</style>
```
## Status

This seems to mostly work in PHP 8.3, but it does not work with PHP 7.4.

So far, I have successfully tested these functionalities in the WordPress Plugin:
* registering a store in `WooCommerce>Settings>Integration>Software Licensor`
* creating a product in `Software Licensor>Create/Update Licensed Product`
* creating a license via a transaction with `software_licensor_id` and `license_type` attributes set on the Product
* displaying licenses in a style-able webpage, a PHP **shortcode** called `software_licensor_licenses_page`
* regenerating a license code via the button on the `software_licensor_licenses_page`, and also verifying that the errors are descriptive enough when trying to regenerate licenses too soon since the last time.
* Exporting and importing the store information

The only things that have not been successfully tested so far are:
* The code that is supposed to insert licenses into the email response after taking an order.

### Things not yet implemented in this WordPress Plugin

* Subscriptions

  * Subscriptions are implemented in the Rust Software Licensor backend, but there are no current plans to integrate the subscriptions in this WordPress plugin. All the code may be there, but for starters... the code for handling subscriptions might vary between use cases. Some users might want to use WC_Subscriptions, other users might look at the reviews of WC_Subscriptions and decide to not go that route, especially given that they cost about $24 per month.

  * If I release any subscriptions, I think I'll give the user a `perpetual` license rather than a `subscription` license.

* Offline License Activation

  * Offline License activation was initially going to involve giving the user a 4-digit code that they would append to their license code when activating the license like so `[license code]-offline-1234`. This would not have been an actual offline activation. The reason for the code was to prevent people from accidentally sharing this code and having some machines permanently filling up spots on their machine lists for their license, as offline machines cannot be reliably deactivated.

  * A better solution would be to add a second button to the `software_licensor_licenses_page` called `Activate Offline License`, that when clicked, would open up a form that allows the user to upload a file, then the Store's backend would send a `license_activation` request on the user's behalf, and the `license_activation_refactor` code would need to be updated to require a valid signature for offline requests, rather than the user only "knowing" the 4-digit offline code.

* Deactivating individual machines on a license
  
  * There is an API method that can remove machines from a license, but it has not been implemented in this WordPress Plugin due to the simplicity of the license regeneration API. The license regeneration method will remove all `online machines` from a license, and return a new license code. It will not remove `offline machines`.

## Site Migrations and Security

It is possible to migrate your website to a new host, but there are some caveats. The store's private key is encrypted using a key and salt from your site's `wp-config.php` file, so you may not be able to simply use a WordPress Site Migration plugin to completely migrate the site, because the private key will not be able to be decrypted successfully, and you will see an error on any page that requires decrypting anything in the database. It is important that the store's private key is encrypted because this is how the `Software Licensor` backend authenticates your requests. By encrypting the private key this way, an attacker would not only have to get a hold of your site's database, but they would also have to get a hold of your site's `wp-config.php` file.

The private key exporting/importing form requires the user to be an admin, and it also asks for the admin's password. The password is checked with the locally stored hash, and then that password is used to encrypt the private key. The same password must be used to decrypt the private key, and this works pretty nicely since site migration tools will copy over the accounts from the old website. **You must remember to use a strong password for your admin accounts for this to be secure.**

After exporting your site's private key, inspect the downloaded file to ensure that the file is a valid `json` file. There's a slight chance that the file will be part of an `HTML` file, and if it is, just try exporting the data again. I ran into some issues when I was developing this feature, and the last time I tried it, it worked, but it could have been a fluke. There was only a single StackOverflow post that I could find that sort-of fixed it, but it was a weird solution for a weird problem.

## Development

Here's an example for compiling the protobuf messages:
```sh
cd software-licensor-wp-plugin/includes/protobufs/protos
protoc --php_out=../generated get_license.proto
```