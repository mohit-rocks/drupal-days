# Sample Project Repository for running and evaluating how dynamic migration works

Note: This is pre-built setup for demo.

If you want to add Drupal VM to your projects via composer, use the following steps.

## Steps

First you need to [install composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx).

> Note: The instructions below refer to the [global composer installation](https://getcomposer.org/doc/00-intro.md#globally).
You might need to replace `composer` with `php composer.phar` (or similar) 
for your setup.

After that clone the repository:

```
git clone git@github.com:mohit-rocks/drupal-days.git
```

Run `composer install` to get drupal core and other required modules.

Install site using `config installer` profile. By default it will have three languages. English, German and French

It will also install `product` content type required for migration along with other multilingual and translation related settings.

## Import content

1. Goto `/admin/content/import/product`.

2. Select `example.csv` from `content_import` custom module and select `English` language. For now, we are taking English as base language.

3. Click on `Import content` and it will import content in English language.

4. For importing content in other languages, select appropriate files from `content_import` and select appropriate language.
    
5. Visit `admin/content` and you can notice translated content along with translation mapping of english nodes.
