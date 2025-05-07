# Sushi Sources

An overkill toolkit for your [Sushi](https://github.com/calebporzio/sushi) models.

If you want to not only read rows from, but also write them to a source like another model's JSON property.

> ⚠ The current major version of this package is 0. Be aware of [what it implies](https://semver.org/#spec-item-4).

## Install
```bash
composer require wilsonseixo/sushi-sources:@dev
```

## Use

_To be defined_

## Sources

### Options

Each Sushi model might have its own requirements. For this reason, you can declare properties on the model to change its behavior.

_insert a model class code snippet here_

Below is a list of the properties you can set to change the sources' behavior for the given model.

| Property                          | Behaviour                                                         |
|-----------------------------------|-------------------------------------------------------------------|
| `array $sourceContext`            | The context to be passed to the sources' constructor.             |
| `bool $persistSushiChanges`       | Whether to `persist` table changes to the source.                 |
| `bool $lockDuringSushiPersist`    | Whether to use the source `lock` while performing changes.        |
| `bool $refreshRowsBeforePersist`  | Whether to `read` rows from the source before persisting changes. |
| `bool $stripPrimaryKeysOnPersist` | Whether to strip primary keys from rows when persisting changes.  |
| `bool $keyRowsOnPersist`          | Whether to key rows by primary key when persisting changes.       |

If you need to change the behavior just for the next action, you can use the following methods:

| Property                           | Toggle on         | Toggle off           |
|------------------------------------|-------------------|----------------------|
| `bool $persistSushiChanges`        | `persistent()`    | `nonPersistent()`    |
| `bool $lockDuringSushiPersist`     | `locking()`       | `nonLocking()`       |
| `bool $refreshRowsBeforePersist`   | `rowRefreshing()` | `nonRowRefreshing()` |
| `bool $stripPrimaryKeysOnPersist`  | `keyStripping()`  | `nonKeyStripping()`  |
| `bool $keyRowsOnPersist`           | `rowKeying()`     | `nonRowKeying()`     |

After an action is performed, the temporary flags are cleared using the method `clearMutatingFlags()`.

### Default sources

The package comes with some default sources.

#### `Source` abstract

Context

| Property             | Type   | Description                                                                                 |
|----------------------|--------|---------------------------------------------------------------------------------------------|
| `refresh_on_read`    | `bool` | Whether the source should be refreshed when `read` is called. (_pending implementation_)    |
| `refresh_on_write`   | `bool` | Whether the source should be refreshed when `write` is called. (_pending implementation_)   |
| `refresh_on_persist` | `bool` | Whether the source should be refreshed when `persist` is called. (_pending implementation_) |

#### `ModelSource`

Context

| Property             | Type                   | Description                                                                                                                                                                                                        |
|----------------------|------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `class`              | `string`               | The model class.                                                                                                                                                                                                   |
| `key`                | `mixed`                | The model instance key the source should fetch using `Model::find()`.                                                                                                                                              |
| `query`              | `array<string, array>` | Alternatively to the key, you can specify Eloquent builder methods to perform the query.<br/> The key is the method and the value is the respective array of arguments (i.e., `['where', ['code', 'something']]`). |
| `model`              | `Model`                | The model instance being used as the source.                                                                                                                                                                       |


#### `JsonFileSource`

Context

| Property            | Type     | Description                                           |
|---------------------|----------|-------------------------------------------------------|
| `filename`          | `string` | The file path to be used as the source.               |
| `json_depth`        | `int`    | `$depth` argument for `json_decode` and `json_encode` |
| `json_decode_flags` | `int`    | `$flags` argument for `json_decode`                   |
| `json_encode_flags` | `int`    | `$flags` argument for `json_encode`                   |


## Notes

### Mass actions
Changes on the sources are triggered by Eloquent events, which means that mass-updating, deleting and inserting will not trigger the `persist()` method on the source.

To solve this problem, you must call static method `persistSushiSource()` (_pending implementation_) on the model to force

_insert code snippet here_

## Roadmap

- Documentation
  - Improve `README.md`
  - Create `EXAMPLES.md`?
- Trait
  - Static method `persistSushiSource()`
- Sources
  - `refresh` flags functionality
    - `refresh_on_read`
    - `refresh_on_write`
    - `refresh_on_persist`
- Examples
- Tests


<br><br>
**Sushi Sources** was created by **[Wilson Ferreira](https://twitter.com/wilsonseixo)** under the **[MIT license](https://opensource.org/licenses/MIT)**.
