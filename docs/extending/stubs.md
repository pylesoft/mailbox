# Stubs

Generate extension stubs:

```bash
php artisan vendor:publish --tag=mailbox-stubs
```

## What You Get

- driver stub
- mailbox/message/folder/attachment resource stubs
- query builder stubs
- DTO stub

## Recommended Workflow

1. copy stubs into your app namespace
2. implement methods incrementally
3. write feature tests against your custom driver
4. register driver only after core operations are green

## Next

- [Custom Drivers](custom-drivers.md)
