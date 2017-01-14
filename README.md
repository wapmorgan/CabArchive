# CabArchive

CabArchive is reader of CAB (Microsoft Cabinet files).

# Usage
Firstly, you need to create CabArchive instance:
```php
$cab = new CabArchive('123.cab');
```
After that you can get list of files in archive:
```php
var_dump($cab->getFileNames());
```
After that you can get all information about one file in archive:
```php
var_dump($cab->getFileData('README.md'));
```
# CabArchive
All list of properties and methods of `CabArchive` is listed below.

- **$filesCount** - number of files in Cab-archive
- **__construct($filename)** - creates new instance from file, stream or socket
- **getCabHeader()** - returns header of Cab-archive as array
- **hasPreviousCab()** - checks that this cab has previous Cab in set
- **getPreviousCab()** - returns name of previous Cab
- **hasNextCab()** - checks that this cab has next Cab in set
- **getNextCab()** - returns name of next Cab
- **getSetId()** - returns set id (identical for all cab-archives from one set)
- **getInSetNumber()** - returns number of cab in set
- **getFileNames()** - retrives list of files from archive
- **getFileData($filename)** - returns additional info of file.
- **getFileContent($filename)** - _in development now_

### getFileData($filename)
This method returns a object with following fields:

- **size** - uncompressed size in bytes
- **unixtime** - date&time of modification in unixtime format
- **is_compressed** - is file compressed as _boolean_
