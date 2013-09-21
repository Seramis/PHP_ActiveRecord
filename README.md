PHP_ActiveRecord
================

For some time, i have had many toughts how things should be different, when using or building different ActiveRecords or ORMs. Now it's time to put those toughts into code and show it to the public.

Let's put some rules down:
* All properties represent only fields in DB
* AR is not supposed to do business logic validation
* Logic must work the same way with created or yet to be created objects
* Definitions of objects must be defined only once (No definition description on __construct()!)
* Definitions should be written in native PHP code for speed, should be possible to generate automaticall at will.
* Caching needs to be implemented: If i get object in one place of code and then object in other place of code, those objects must be same instances, if their ID is same.

Also, code must be strict but also leave you as much freedom as possible. Strict and freedom are not opposite words!

So by thinking about those rules, here is something, i have done.

Usage
=====

Let's create one really nice User model:
```php
class User extends \AR\ActiveRecord
{
	protected static $aDefinition = array(
		'sTable' => 'user',
		'sIdField' => 'user_id',
		'aField' => array(
			'user_id',
			'firstname',
			'surname',
			'mail'
		)
	);

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

	protected function _preSave_mail($sMail)
	{
		if(!strstr($sMail, '@'))
		{
			return false;
		}
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
	* Before lazy load
	* Values are not available yet (if not loaded previously)
	* Can return false to so get is prevented and false is returned by ActiveRecord
* _preGet_field_name()
	* Before lazy load
    * Values are not available yet (if not loaded previously)
    * Can return false to so get is prevented and false is returned by ActiveRecord
* _postGet_field_name(&field_value)
	* After lazyload
	* Field value is now available
	* Value can be modified
* _postGet(field_name, &field_value)
	* After lazyload
	* Field value is now available
	* Value can be modified

Set
---
* _preSet(field_name, &field_value)
	* Before set
	* Can modify value
	* Can return false to prevent set
* _preSet_field_name(&field_value)
	* Before set
	* Can modify value
	* Can return false to prevent set
* _postSet_field_name(field_value)
	* After set
* _postSet(field_name, field_value)
	* After set

Save
---
* _preSave(&field_value_array)
	* Before saving
	* Can modify array of fields
	* Can return false to prevent save
* _preSave_field_name(&field_value)
	* Before saving
	* Can modify value
	* Can return false to prevent save
* _postSave_field_name(field_value)
	* After saving
* _postSave(field_value_array)
	* After saving

Let's play!
===========
```php
$oUser = new User();

$oUser->firstname = 'john';
//Trigger _preSet_firstname() modifies it to 'John'

$oUser->surname = 'doe';
//Trigger _preSet_surname() modifies it to 'Doe'

$oUser->save() == false;
//Trigger _preSave_mail() prevents us to save ActiveRecord

$oUser->mail = 'john@doe.com';

$oUser->save() == true;
//INSERT INTO user SET firstname = 'John', surname = 'Doe', mail = 'john@doe.com'

$oUser2 = User::getOne(array('user_id' => $oUser->getId()));

$oUser === $oUser2; //They really are equal thanks to cache

$oUser->firstname = 'foo bar';
//Trigger _preSet_firstname() modifies it to 'Foo Bar'

$oUser->save() == true;
//UPDATE user SET firstname = 'Foo Bar' WHERE user_id = '1'

echo $oUser->firstname;
//Lazy-load does loading if needed
//Trigger _postGet_firstname() makes this value to 'Foo Bar' (even, if it is 'foo bar' in DB)
```