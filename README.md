PHP_ActiveRecord
================

For some time, i have had many toughts how things should be different, when using or building different ActiveRecords or ORMs. Now it's time to put those toughts into code and show it to the public.

Let's put some rules down:
* All properties represent only fields in DB
* AR is not supposed to do business logic validation
* Logic must work the same way with created or yet to be created objects
* Definitions of objects must be defined only once (No definition description on __construct()!)
* Definitions should be written in native PHP code for speed, should be possible to generate automaticall at will.

Also, code must be strict but also leave you as much freedom as possible. Strict and freedom are not opposite words!

So by thinking about those rules, here is something, i have done.

Usage
=====

Let's create one really nice User model:
```php
<?php
class User extends \AR\ActiveRecord
{
}
```

And now, let's play with it.
