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
	//Definition of ActiveRecord in DB
	protected static $aDefinition = array(
		'sTable' => 'user',
		'sIdField' => 'user_id',
		'aField' => array(
			'user_id',
			'username',
			'password',
			'firstname',
			'surname',
			'mail'
		)
	);

	//Triggers

	protected function _postGet_firstname(&$sFirstName)
	{
		$sFirstName = ucwords(strtolower($sFirstName));
	}

	protected function _postGet_surname(&$sFirstName)
	{
		$sFirstName = ucwords(strtolower($sFirstName));
	}

	protected function _preSet_firstname(&$sFirstName)
	{
		$sFirstName = ucwords(strtolower($sFirstName));
	}

	protected function _preSet_surname(&$sFirstName)
	{
		$sFirstName = ucwords(strtolower($sFirstName));
	}
}
```

So what is this model capable of?
First it contains definition of the active record. Quite simple, probably doesn't need any more explanation. But then it gets interesting.

It uses triggers to keep firstname ans surname field value in proper format, that is every name starts with capital letter and the rest is lowercased. It is done in postGet triggers (to get proper values from DB) and preSet triggers. (So proper names are going in to the DB)

So...

Triggers
========

ActiveRecord has ability to execute different triggers. Triggers are (in order):
Get
---
* _preGet(field_name)
* _preGet_field_name()
* _postGet_field_name(field_value)
* _postGet(field_name, field_value)

Set
---
* _preSet(field_name, field_value)
* _preSet_field_name(field_value)
* _postSet_field_name(field_value)
* _postSet(field_name, field_value)

Save
---
* _preSave(field_value_array)
* _preSave_field_name(field_value)
* _postSave_field_name(field_value)
* _postSave(field_value_array)

All triggers are supported by having parameters as references. That means, you can do some data manipulation inside a trigger.
