# Self-check fixture: the public directory listing, written in English

This plugin captures the buyer tax identifier at checkout and delivers paid
orders and refunds to Factuarea through its own outbox, with its own backoff
and its own idempotency.

## Configuration

Register the store in Factuarea, generate the signing secret on the settings
screen and paste it into the store record. Then run the connection test.

The prose below is code, and code is not prose. The fenced block and the inline
span both carry Spanish on purpose: if either rule stopped working, this file
would start failing the gate and the test that reads it would say so.

```php
// Este bloque vive dentro de una valla de codigo y el gate no lo inspecciona.
$mensaje = 'La factura del pedido ya esta disponible';
```

An inline span such as `la factura de la tienda` is removed from the line before
the line is inspected, for the same reason.
