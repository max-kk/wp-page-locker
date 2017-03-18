# wp-page-locker - Wordpress admin pages locker
Allows you lock any admin page from multuply users editing (like Wordpress posts).
Common usage target - avoid multiply users override their changes.

Using example: look at **locker-init.php**

Original code was taken from [Gravity Forms plugin](https://github.com/wp-premium/gravityforms/tree/master/includes/locking
0and slighty simplified.

> For make this library worked you need have [WP Heartbeat](https://code.tutsplus.com/tutorials/the-heartbeat-api-getting-started--wp-32446) enabled.

## How is it working?

When any user open the locked page library set Transient to 130 seconds and with each Heartbeat request this Transient is prolonged.

When another user (with the other ID or even the same ID but diff IP) trying open this page he get a notice that page was locked by "User AAA" and possible actions - close page or request access to page.

## Example: 

![wp-page-locker example](https://res.cloudinary.com/dxo61viuo/image/upload/v1489837957/git/example1.png)

### Version 1.0
