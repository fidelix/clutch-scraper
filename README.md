# Clutch Scraper
This project is a crawler that scrapes and sanitizes web development agencies from clutch.co.

# Requirements
- PDO Extension
- PDO Sqlite
- PHP 7.1+ (tested with PHP 7.2)

First, install dependencies and initialize the database with:

```
composer install
cat schema.sql | sqlite3 agencies.sqlite
```

# Usage

##### Do everything at once:
```
php clutch.php --list --metadata --csv=uk.csv --country="United Kingdom"
```

##### In steps (recommended):

1 - Populate the local database with a list of agencies:
```
php clutch.php --list --country="United Kingdom"
```
2 - Fetch the metadata of the agencies
```
php clutch.php --metadata --country="United Kingdom"
```
3 - Export the database to CSV (has to be populated first)
```
php clutch.php --csv=agencies.csv --country="United Kingdom"
```

### Options
```
# Export to CSV
--csv="uk.csv"
# Offset page list (requires --list)
--offset=5 
# Disable cache (re-fetch agency metadata)
--no-cache
```

# TODO

- Retry unsuccessful operations
- Implement (configurable) timeouts
