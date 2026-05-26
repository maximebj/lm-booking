# LM Booking — règles projet

## Checkout WooCommerce

Ce projet utilise exclusivement le **WooCommerce Checkout Block** (Store API). Le checkout classique (`[woocommerce_checkout]`) n'est pas utilisé.

Pour tout développement lié au checkout, cibler les hooks Store API :

- `woocommerce_store_api_checkout_order_processed` — équivalent de `woocommerce_checkout_order_created`
- `woocommerce_store_api_checkout_update_order_meta` — équivalent de `woocommerce_checkout_update_order_meta`
- Pour la validation, utiliser `throw new \Exception()` plutôt que `wc_add_notice()`
