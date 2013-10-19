# PHP ActiveRecord #
For some time, i have had many thoughts how things should be different, when using or building different ActiveRecords or ORMs. Now it's time to put those thoughts into code and show it to the public.

Let's put some rules down:
* All properties represent only fields in DB
* All method really do exist, nothing is generated on the fly (How should i know, what kind of methods there are? I want my IDE to show me all options!)
* AR is not supposed to do business logic validation
* Logic must work the same way with created or yet to be created objects
* Definitions of objects must be defined only once (No definition description in __construct()!)
* Definitions should be written in native PHP code for speed, should be possible to generate automatically at will.
* Caching needs to be implemented: If i get object in one place of code and then object in other place of code, those objects must be same instances, if their ID is same.
* Only necessary methods are provided as protected and public. (No bloat when you write _your_ software)
* You should **not** learn new query language as you probably already know SQL
* It must be super simple to plug it in to any project.

Also, code must be strict but also leave you as much freedom as possible. Strict and freedom are not opposite words!

So by thinking about those rules, here is something, i have done.

## Usage ##
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

	protected function _preDelete()
	{
		if($this->firstname != 'Foo Bar')
		{
			return false;
		}
	}

	protected function _postDelete()
	{
		echo "Good bye!";
	}
}
```

So what is this model capable of?
First it contains definition of the active record. Quite simple, probably doesn't need any more explanation. But then it gets interesting.

It uses triggers to keep firstname and surname field value in proper format, that is every name starts with capital letter and the rest is lowercased. It is done in postGet triggers (to get proper values from DB) and preSet triggers. (So proper names are going in to the DB)
It also uses preSave trigger to prevent saving, if saved e-mail does not include '@' character.

So...

## Triggers ##
ActiveRecord has ability to execute different triggers. Triggers are (in order):

### Get ###
* `_preGet(field_name)`
	* Before lazy load
	* Values are not available yet (if not loaded previously)
	* Can return false so get is prevented and false is returned by ActiveRecord
* `_preGet_field_name()`
	* Before lazy load
    * Values are not available yet (if not loaded previously)
    * Can return false so get is prevented and false is returned by ActiveRecord
* `_postGet_field_name(&field_value)`
	* After lazyload
	* Field value is now available
	* Value can be modified
* `_postGet(field_name, &field_value)`
	* After lazyload
	* Field value is now available
	* Value can be modified

### Set ###
* `_preSet(field_name, &field_value)`
	* Before set
	* Can modify value
	* Can return false to prevent set
* `_preSet_field_name(&field_value)`
	* Before set
	* Can modify value
	* Can return false to prevent set
* `_postSet_field_name(field_value)`
	* After set
* `_postSet(field_name, field_value)`
	* After set

### Save ###
* `_preSave(&field_value_array)`
	* Before saving
	* Can modify array of fields
	* Can return false to prevent save
* `_preSave_field_name(&field_value)`
	* Before saving
	* Can modify value
	* Can return false to prevent save
* `_postSave_field_name(field_value)`
	* After saving
* `_postSave(field_value_array)`
	* After saving

### Delete ###
* `_preDelete()`
	* Before delete
	* All object data still usable
	* Can return false to prevent delete command
* `_postDelete()`
	* After deletion is done
	* Object is removed from cache
	* Object data is still available to use here

## Let's play! ##
```php
\AR\ActiveRecord::setPdo(new PDO('mysql:host=localhost;dbname=db_name;charset=utf8', 'username', 'password'));
//Set up connection for ActiveRecord to use

$oUser = new User();

$oUser->firstname = 'john';
//Trigger _preSet_firstname() modifies it to 'John'

$oUser->surname = 'doe';
//Trigger _preSet_surname() modifies it to 'Doe'

$oUser->save() === false;
//Trigger _preSave_mail() prevents us to save ActiveRecord

$oUser->mail = 'john@doe.com';

$oUser->save() === true;
//INSERT INTO user (`firstname`,`surname`,`mail`) VALUES ('John', 'Doe', 'john@doe.com')

$oUser->save() === null;
//We have nothing to save anymore

/*
 * Let's get that user in different ways
 */

$oUserById = User::getById($oUser->getId());
//Goes against cache, if it isn't in cache, uses self::getOne()

$oUserOne = User::getOne(array('%self.id% = ?' => $oUser->getId()));
//Calls self::getMany() internally and takes first element
//As I don't have to know, what is the id field for User, I can use %self.id%.

$aUserMany = User::getMany(array('%self.id% = ?' => $oUser->getId()));
//Returns list of object by array of conditions where key is condition and value is NULL, one value or array of values

$aUserWhere = User::getManyByWhere('%self.id% = ?', array($oUser->getId()));
//Returns list of objects by self-written WHERE. Second argument is for parameters.

$aUserSql = User::getManyBySql('SELECT %self.id% FROM %self.table% WHERE %self.id% = ?', array($oUser->getId()));
//Get list of users by sql.
//If we use *, ActiveRecord will find that all fields are present and will prefill object

$oUser === $oUserById;
$oUserById === $oUserOne;
$oUserOne === $aUserMany[0];
$aUserMany[0] === $aUserWhere[0];
$aUserWhere[0] === $aUserSql[0];
//They really are identical thanks to cache

$oUser->delete() === false;
//Trigger _preDelete() prevents delete command

$oUser->firstname = 'foo bar';
//Trigger _preSet_firstname() modifies it to 'Foo Bar'

$oUser->save() === true;
//UPDATE `user` SET `firstname` = 'Foo Bar' WHERE `user_id` = '1'

echo $oUser->firstname;
//Lazy-load does loading if needed
//Trigger _postGet_firstname() makes this value to 'Foo Bar' (even, if it is 'foo bar' in DB)

$oUser->delete() === true;
//DELETE FROM `user` WHERE `user_id` = 1
//Trigger _postDelete() will echo 'Good bye!'

$oUser->save();
//And now, insert happens just like we would create new object.
//INSERT INTO user (`firstname`,`surname`,`mail`) VALUES ('Foo Bar', 'Doe', 'john@doe.com')
```

## N+1 SELECT problem? ##
We have a solution for that too! Check that out:
```php
$aPost = Post::getManyBySql(
	"SELECT %self.table%.*, %User.table%.* FROM %self.table% INNER JOIN %User.table% ON %self.table%.%User.id% = %User.table%.%User.id%"
);

foreach($aPost as $oPost)
{
	echo $oPost->content . ' by ' . User::getById($oPost->user_id)->firstname . '<br />';
}
```
**What happened?**
As we selected data from multiple tables, ActiveRecord was able to cache all User objects what were found. (while also parsing keywords) Now when we loop through posts and ask for User model with getById() method, object is returned from cache.

It supports all kinds of joins. It's basic SQL. So if you know, that querying out something in extra will benefit performance very well, you can do that, and ActiveRecord will cache all objects. Can it get any better?

But another interesting thing is shown here. `%placeholders%`

## Placeholders ##
Placeholders can be used in sql to refer to model's properties like table name or id field name. The format is `%Model.property%`.
Properties can be `table` and `id`, which represent table name and id field name accordingly.

So when we are asking for posts, we can refer to post table like `%self.table%` or `%Post.table%`. `self` is a special keyword that is translated to Model, you are asking.

**Remember that the first part is model name, not table name.** You don't have to know any table's name or write them into sql.

## What about data validators? ##
ActiveRecord is row in DB. DB row doesn't know, how to validate data. It knows, how to hold data. So, data validation is not a job to be done by ActiveRecord.
If you want to add validator, it's easy: you get your favorite data validator and attach it to triggers ActiveRecord provides. No chemistry, everything is plain simple!

## Licence ##
The MIT License (MIT)

Copyright (c) 2013 Joonatan Uusväli

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
