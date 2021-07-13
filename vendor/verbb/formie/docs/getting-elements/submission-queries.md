# Submission Queries

You can fetch submissions in your templates or PHP code using **submission queries**.

:::code
```twig
{# Create a new submission query #}
{% set myQuery = craft.formie.submissions() %}
```

```php
// Create a new submission query
$myQuery = \verbb\formie\elements\Submission::find();
```
:::

Once you’ve created a submission query, you can set parameters on it to narrow down the results, and then execute it by calling `.all()`. An array of [Submission](docs:developers/submission) objects will be returned.

:::tip
See Introduction to [Element Queries](https://docs.craftcms.com/v3/dev/element-queries/) in the Craft docs to learn about how element queries work.
:::

## Example

We can display submissions for a given form by doing the following:

1. Create an submission query with `craft.formie.submissions()`.
2. Set the [form](#form) and [limit](#limit) parameters on it.
3. Fetch all submissions with `.all()` and output.
4. Loop through the submissions using a [for](https://twig.symfony.com/doc/2.x/tags/for.html) tag to output the contents.

```twig
{# Create a submissions query with the 'form' and 'limit' parameters #}
{% set submissionsQuery = craft.formie.submissions()
    .form('contactForm')
    .limit(10) %}

{# Fetch the Submissions #}
{% set submissions = submissionsQuery.all() %}

{# Display their contents #}
{% for submission in submissions %}
    <p>{{ submission.title }}</p>
{% endfor %}
```

## Parameters

Submission queries support the following parameters:

<!-- BEGIN PARAMS -->

| Param                                     | Description
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
| [anyStatus](#anystatus)                       | Clears out the [status](#status)
| [asArray](#asarray)                           | Causes the query to return matching submissions as arrays of data, rather than Submission objects.
| [dateCreated](#datecreated)                   | Narrows the query results based on the submissions’ creation dates.
| [dateUpdated](#dateupdated)                   | Narrows the query results based on the submissions’ last-updated dates.
| [fixedOrder](#fixedorder)                     | Causes the query results to be returned in the order specified by [id](#id).
| [form](#form)                                 | Narrows the query results based on the submissions’ types.
| [formId](#formId)                             | Narrows the query results based on the submissions’ types, per the types’ IDs.
| [id](#id)                                     | Narrows the query results based on the submissions’ IDs.
| [inReverse](#inreverse)                       | Causes the query results to be returned in reverse order.
| [isIncomplete](#isIncomplete)                 | Narrows the query results to only submissions that are incomplete.
| [isSpam](#isSpam)                             | Narrows the query results to only submissions that are marked as spam.
| [limit](#limit)                               | Determines the number of submissions that should be returned.
| [offset](#offset)                             | Determines how many submissions should be skipped in the results.
| [orderBy](#orderby)                           | Determines the order that the submissions should be returned in. (If empty, defaults to `postDate DESC`.)
| [relatedTo](#relatedto)                       | Narrows the query results to only submissions that are related to certain other elements.
| [status](#status)                             | Narrows the query results based on the submissions’ statuses.
| [statusId](#statusId)                         | Narrows the query results based on the submissions’ statuses, per their IDs.
| [title](#title)                               | Narrows the query results based on the submissions’ titles.
| [uid](#uid)                                   | Narrows the query results based on the submissions’ UIDs.



### `anyStatus`

Clears out the [status()](https://docs.craftcms.com/api/v3/craft-elements-db-elementquery.html#method-status) and [enabledForSite()](https://docs.craftcms.com/api/v3/craft-elements-db-elementquery.html#method-enabledforsite) parameters.

::: code
```twig
{# Fetch all submissions, regardless of status #}
{% set submissions = craft.formie.submissions()
    .anyStatus()
    .all() %}
```

```php
// Fetch all submissions, regardless of status
$submissions = \verbb\formie\elements\Submission::find()
    ->anyStatus()
    ->all();
```
:::



### `asArray`

Causes the query to return matching submissions as arrays of data, rather than [Submission](docs:developers/submission) objects.

::: code
```twig
{# Fetch submissions as arrays #}
{% set submissions = craft.formie.submissions()
    .asArray()
    .all() %}
```

```php
// Fetch submissions as arrays
$submissions = \verbb\formie\elements\Submission::find()
    ->asArray()
    ->all();
```
:::



### `dateCreated`

Narrows the query results based on the submissions’ creation dates.

Possible values include:

| Value | Fetches submissions…
| - | -
| `'>= 2018-04-01'` | that were created on or after 2018-04-01.
| `'< 2018-05-01'` | that were created before 2018-05-01
| `['and', '>= 2018-04-04', '< 2018-05-01']` | that were created between 2018-04-01 and 2018-05-01.

::: code
```twig
{# Fetch submissions created last month #}
{% set start = date('first day of last month') | atom %}
{% set end = date('first day of this month') | atom %}

{% set submissions = craft.formie.submissions()
    .dateCreated(['and', ">= #{start}", "< #{end}"])
    .all() %}
```

```php
// Fetch submissions created last month
$start = new \DateTime('first day of next month')->format(\DateTime::ATOM);
$end = new \DateTime('first day of this month')->format(\DateTime::ATOM);

$submissions = \verbb\formie\elements\Submission::find()
    ->dateCreated(['and', ">= {$start}", "< {$end}"])
    ->all();
```
:::



### `dateUpdated`

Narrows the query results based on the submissions’ last-updated dates.

Possible values include:

| Value | Fetches submissions…
| - | -
| `'>= 2018-04-01'` | that were updated on or after 2018-04-01.
| `'< 2018-05-01'` | that were updated before 2018-05-01
| `['and', '>= 2018-04-04', '< 2018-05-01']` | that were updated between 2018-04-01 and 2018-05-01.

::: code
```twig
{# Fetch submissions updated in the last week #}
{% set lastWeek = date('1 week ago')|atom %}

{% set submissions = craft.formie.submissions()
    .dateUpdated(">= #{lastWeek}")
    .all() %}
```

```php
// Fetch submissions updated in the last week
$lastWeek = new \DateTime('1 week ago')->format(\DateTime::ATOM);

$submissions = \verbb\formie\elements\Submission::find()
    ->dateUpdated(">= {$lastWeek}")
    ->all();
```
:::



### `fixedOrder`

Causes the query results to be returned in the order specified by [id](#id).

::: code
```twig
{# Fetch submissions in a specific order #}
{% set submissions = craft.formie.submissions()
    .id([1, 2, 3, 4, 5])
    .fixedOrder()
    .all() %}
```

```php
// Fetch submissions in a specific order
$submissions = \verbb\formie\elements\Submission::find()
    ->id([1, 2, 3, 4, 5])
    ->fixedOrder()
    ->all();
```
:::



### `form`

Narrows the query results based on the submissions’ form.

Possible values include:

| Value | Fetches submissions…
| - | -
| `'foo'` | for a form with a handle of `foo`.
| `'not foo'` | not for a form with a handle of `foo`.
| `['foo', 'bar']` | for a form with a handle of `foo` or `bar`.
| `['not', 'foo', 'bar']` | not for a form with a handle of `foo` or `bar`.
| an [Form](docs:developers/form) object | for a form represented by the object.

::: code
```twig
{# Fetch submissions from a Foo form #}
{% set submissions = craft.formie.submissions()
    .form('foo')
    .all() %}
```

```php
// Fetch submissions from a Foo form
$submissions = \verbb\formie\elements\Submission::find()
    ->form('foo')
    ->all();
```
:::



### `formId`

Narrows the query results based on the submissions’ form IDs.

Possible values include:

| Value | Fetches submissions…
| - | -
| `1` | for a form with an ID of 1.
| `'not 1'` | not for a form with an ID of 1.
| `[1, 2]` | for a form with an ID of 1 or 2.
| `['not', 1, 2]` | not for a form with an ID of 1 or 2.

::: code
```twig
{# Fetch submissions for the form with an ID of 1 #}
{% set submissions = craft.formie.submissions()
    .formId(1)
    .all() %}
```

```php
// Fetch submissions for the form with an ID of 1
$submissions = \verbb\formie\elements\Submission::find()
    ->formId(1)
    ->all();
```
:::




### `id`

Narrows the query results based on the submissions’ IDs.

Possible values include:

| Value | Fetches submissions…
| - | -
| `1` | with an ID of 1.
| `'not 1'` | not with an ID of 1.
| `[1, 2]` | with an ID of 1 or 2.
| `['not', 1, 2]` | not with an ID of 1 or 2.

::: code
```twig
{# Fetch the submission by its ID #}
{% set submission = craft.formie.submissions()
    .id(1)
    .one() %}
```

```php
// Fetch the submission by its ID
$submission = \verbb\formie\elements\Submission::find()
    ->id(1)
    ->one();
```
:::

::: tip
This can be combined with [fixedOrder](#fixedorder) if you want the results to be returned in a specific order.
:::



### `inReverse`

Causes the query results to be returned in reverse order.

::: code
```twig
{# Fetch submissions in reverse #}
{% set submissions = craft.formie.submissions()
    .inReverse()
    .all() %}
```

```php
// Fetch submissions in reverse
$submissions = \verbb\formie\elements\Submission::find()
    ->inReverse()
    ->all();
```
:::



### `limit`

Determines the number of submissions that should be returned.

::: code
```twig
{# Fetch up to 10 submissions  #}
{% set submissions = craft.formie.submissions()
    .limit(10)
    .all() %}
```

```php
// Fetch up to 10 submissions
$submissions = \verbb\formie\elements\Submission::find()
    ->limit(10)
    ->all();
```
:::



### `offset`

Determines how many submissions should be skipped in the results.

::: code
```twig
{# Fetch all submissions except for the first 3 #}
{% set submissions = craft.formie.submissions()
    .offset(3)
    .all() %}
```

```php
// Fetch all submissions except for the first 3
$submissions = \verbb\formie\elements\Submission::find()
    ->offset(3)
    ->all();
```
:::



### `orderBy`

Determines the order that the submissions should be returned in.

::: code
```twig
{# Fetch all submissions in order of date created #}
{% set submissions = craft.formie.submissions()
    .orderBy('elements.dateCreated asc')
    .all() %}
```

```php
// Fetch all submissions in order of date created
$submissions = \verbb\formie\elements\Submission::find()
    ->orderBy('elements.dateCreated asc')
    ->all();
```
:::



### `relatedTo`

Narrows the query results to only submissions that are related to certain other elements.

See [Relations](https://docs.craftcms.com/v3/relations.html) for a full explanation of how to work with this parameter.

::: code
```twig
{# Fetch all submissions that are related to myCategory #}
{% set submissions = craft.formie.submissions()
    .relatedTo(myCategory)
    .all() %}
```

```php
// Fetch all submissions that are related to $myCategory
$submissions = \verbb\formie\elements\Submission::find()
    ->relatedTo($myCategory)
    ->all();
```
:::



### `status`

Narrows the query results based on the submissions’ statuses.

Possible values include:

| Value | Fetches submissions…
| - | -
| `'live'` _(default)_ | that are live.
| `'pending'` | that are pending (enabled with a Post Date in the future).
| `'expired'` | that are expired (enabled with an Expiry Date in the past).
| `'disabled'` | that are disabled.
| `['live', 'pending']` | that are live or pending.

::: code
```twig
{# Fetch disabled submissions #}
{% set submissions = craft.formie.submissions()
    .status('disabled')
    .all() %}
```

```php
// Fetch disabled submissions
$submissions = \verbb\formie\elements\Submission::find()
    ->status('disabled')
    ->all();
```
:::



### `statusId`

Narrows the query results based on the submission statuses, per their IDs.

Possible values include:

| Value | Fetches submissions…
| - | -
| `1` | with a status with an ID of 1.
| `'not 1'` | not with a status with an ID of 1.
| `[1, 2]` | with a status with an ID of 1 or 2.
| `['not', 1, 2]` | not with a status with an ID of 1 or 2.

::: code
```twig
{# Fetch submissions with a status with an ID of 1 #}
{% set submissions = craft.formie.submissions()
    .statusId(1)
    .all() %}
```

```php
// Fetch submissions with a status with an ID of 1
$submissions = \verbb\formie\elements\Submission::find()
    ->statusId(1)
    ->all();
```
:::




### `title`

Narrows the query results based on the submissions’ titles.

Possible values include:

| Value | Fetches submissions…
| - | -
| `'Foo'` | with a title of `Foo`.
| `'Foo*'` | with a title that begins with `Foo`.
| `'*Foo'` | with a title that ends with `Foo`.
| `'*Foo*'` | with a title that contains `Foo`.
| `'not *Foo*'` | with a title that doesn’t contain `Foo`.
| `['*Foo*', '*Bar*'` | with a title that contains `Foo` or `Bar`.
| `['not', '*Foo*', '*Bar*']` | with a title that doesn’t contain `Foo` or `Bar`.

::: code
```twig
{# Fetch submissions with a title that contains "Foo" #}
{% set submissions = craft.formie.submissions()
    .title('*Foo*')
    .all() %}
```

```php
// Fetch submissions with a title that contains "Foo"
$submissions = \verbb\formie\elements\Submission::find()
    ->title('*Foo*')
    ->all();
```
:::



### `uid`

Narrows the query results based on the submissions’ UIDs.

::: code
```twig
{# Fetch the submission by its UID #}
{% set submission = craft.formie.submissions()
    .uid('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
    .one() %}
```

```php
// Fetch the submission by its UID
$submission = \verbb\formie\elements\Submission::find()
    ->uid('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
    ->one();
```
:::

<!-- END PARAMS -->
