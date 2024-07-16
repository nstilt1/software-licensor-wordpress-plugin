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

## Example styles and scripts for the `[software_licensor_licenses_page]`
```html
<style>
.licenses {
  width: 100%;
}
.SL-license-code-header {
  font-size: 20px;
  padding-bottom: 0.25em;
}

.SL-license-code {
  font-size: 16px;
  background-color: #f3f3f3f3;
  cursor: pointer;
}

.SL-licenses-table {
  width: 100%;
  padding-top: 1em;
  text-align: left;
  border: 1px solid;
  border-collapse: collapse;
}

.SL-licenses-table>tbody>tr {
  cursor: pointer;
}

.machine-table {
  border: 1px solid;
  border-collapse: collapse;
  width: 100%;
}
</style>
<script>
  document.getElementsByClassName('SL-license-code')[0].addEventListener('click', function() {
    navigator.clipboard.writeText(this.innerText)
        .then(() => {
            // Show notification on success; change the id based on your html
            let notification = document.getElementById('div_block-6-2016');
            notification.style.opacity = "1.0"; // Make the notification visible

            // Hide the notification after 5 seconds
            setTimeout(() => {
                notification.style.opacity = "0.0";
            }, 5000);
        })
        .catch(err => {
            // Handle possible errors
            console.error('Error copying text: ', err);
        });
});
</script>
```
## Status

This seems to mostly work in PHP 8.3, but it does not work with PHP 7.4.

So far, I have successfully tested these functionalities in the WordPress Plugin:
* registering a store in `WooCommerce>Settings>Integration>Software Licensor`
* creating a product in `Software Licensor>Create/Update Licensed Product`
* creating a license via a transaction with `software_licensor_id` and `license_type` attributes set on the Product
* displaying licenses in a style-able webpage, a PHP **shortcode** called `software_licensor_licenses_page`
* regenerating a license code via the button on the `software_licensor_licenses_page`, and also verifying that the errors are descriptive enough when trying to regenerate licenses too soon since the last time.

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


## Development

Here's an example for compiling the protobuf messages:
```sh
cd software-licensor-wp-plugin/includes/protobufs/protos
protoc --php_out=../generated get_license.proto
```