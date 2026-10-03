=== Spellexo 3D & AR for WooCommerce ===
Contributors: doubleedged1
Tags: woocommerce, 3d, ar, augmented reality, product viewer
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let shoppers explore products in lifelike 3D and place them in their room with AR. Works with WooCommerce products and variations.

== Description ==

Give shoppers a closer look at what they are buying. [Spellexo for WooCommerce](https://www.spellexo.com/woocommerce-3d-product-viewer-plugin) lets customers explore your products from every angle and see how they fit in their own space, alongside your existing product photos.

https://www.youtube.com/watch?v=3OgCWjlGBW4

**Bring your products to life**

* Let shoppers rotate, zoom, and inspect materials, finishes, and fine product details.
* Show products at room scale with augmented reality.
* Reach iPhone, iPad, and a wider range of Android devices, including devices without native AR support.
* Give individual products and variations their own 3D models.
* Add the viewer button with a WordPress block, Elementor widget, or shortcode.
* Manage your models, product assignments, analytics, and account in the Spellexo dashboard.

**AR that reaches more shoppers**

Spellexo's browser-based AR extends beyond devices with built-in native AR support. More shoppers can see your products in their own room without installing a separate app. AR requires a compatible mobile device and browser, plus camera permission.

**Lifelike detail with Spellexo**

The proprietary .spellexo format combines Gaussian splats with geometry and Spellexo's custom shaders to preserve lifelike materials and product detail. Upload an existing model or request model creation and conversion through Spellexo.

Every plan accepts .spellexo files. Paid plans also accept .sog Gaussian splat and .glb files. Model creation and conversion services are quoted separately.

**Start free**

A Spellexo account is required. The Free plan includes one active product or variation and .spellexo uploads, with no card required. Paid plans support larger catalogs and additional upload formats. See [Spellexo pricing](https://www.spellexo.com/pricing) for current plans and limits.

**Fits your product pages**

Your product photos stay in place. Add one **View In Your Room** button to your Single Product template using the included block, optional Elementor widget, or shortcode. The viewer opens when a shopper clicks. Products without an active model do not show an active viewer button.

== Screenshots ==

1. Explore a sofa in the Spellexo 3D viewer, with room placement available from the View in your room button.
2. Connect your WooCommerce store, open your Spellexo dashboard, and synchronize your catalog.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate Spellexo.
3. Open **WooCommerce → Spellexo**, click **Connect to Spellexo**, and sign in or create your Spellexo account in the dashboard tab.
4. Synchronize your catalog, upload or request a model, assign it to a product or variation, and set it live within your plan limit.
5. Add **Spellexo — View In Your Room** to each applicable Single Product template using the WordPress block, Elementor widget, or shortcode: `[spellexo_view_in_your_room]`.

== External services ==

Spellexo is a hosted service that provides model processing and storage, 3D and AR viewing, catalog synchronization, analytics, and account billing. A Spellexo account is required; Free and paid plans are available.

Installing or activating the plugin does not contact Spellexo. The plugin opens `dashboard.spellexo.com` when you choose to connect or open the dashboard. After you connect your store, it uses `api.spellexo.com` to synchronize products, check model availability, provide the viewer, and record shopping activity associated with model viewing.

Data shared after connection includes your store address and software versions, product and variation information, model assignments, and viewer activity. Shopping activity analytics use product and variation identifiers, quantities, event times, and random attribution references. These activity records do not include prices, order numbers, customer names or contact details, passwords, or payment information.

The hosted viewer and API also process IP addresses for security and abuse prevention, browser and device information to operate the viewer, performance measurements, and country or region derived from IP where available. Spellexo retains aggregate analytics and performance data. Optional raw viewer-event logs are disabled by default and are deleted with the store if enabled. Operational logs are kept only as long as needed for reliability and security investigations. Camera access is requested only when a shopper chooses to use AR.

[Spellexo Terms of Service](https://www.spellexo.com/terms)

[Spellexo Privacy Policy](https://www.spellexo.com/privacy)

Paid plans and separately quoted conversion or revision work use Stripe checkout through the Spellexo dashboard. Card details are entered on Stripe's checkout, never in this plugin.

== Frequently Asked Questions ==

= Does Spellexo replace my product photos? =

No. Your existing gallery stays in place. Shoppers open the 3D experience from the View In Your Room button.

= Do I need to add a button to each product manually? =

Add the Spellexo block, Elementor widget, or shortcode once to each applicable Single Product template. It uses the current product and selected variation automatically. Products without an active model do not show an active viewer button.

= Does AR only work on devices with native AR support? =

No. Spellexo also supports browser-based AR on devices without native AR support, including many Android devices. A compatible mobile browser and camera permission are required.

= Can I use my existing Gaussian splat or 3D files? =

Every plan accepts .spellexo files. Paid plans also accept .sog and .glb files. The Free plan accepts .spellexo only. Upload your models in the Spellexo dashboard, or request a separately quoted creation or conversion service.

= Do I need a Spellexo account or paid subscription? =

A Spellexo account is required to connect your store. The Free plan needs no card. Upgrade when you need more active products or additional upload formats.

= Does deleting the plugin cancel my subscription? =

No. Manage or cancel your Spellexo subscription in the dashboard. Removing the plugin does not cancel billing or delete your hosted models.

= Do product changes synchronize automatically? =

Yes. After connection, WooCommerce product and variation changes synchronize automatically. If a third-party import bypasses WooCommerce's normal update process, run a catalog synchronization from the Spellexo panel.

== Privacy ==

Suggested privacy-policy text is available under **Settings → Privacy**. Connection credentials are stored encrypted. See the External services section and Spellexo Privacy Policy for details of data processing.

== Accessibility and compatibility ==

The viewer button and dialog support keyboard navigation, Escape-to-close, focus return, visible focus indicators, and reduced motion. WooCommerce 7.3 or later is required. Shopping activity analytics support classic WooCommerce checkout and Cart/Checkout Blocks.
