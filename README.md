# General
This is a laravel library to help with Data migrations from old databases. Can also help with getting some remote
database data and migrate it to a your database(s) 

####Not production ready yet!!!

# Installation

Add the service provider inside `config/app.php`

```
'providers' => [
...
Despark\Migrations\Providers\MigrationServiceProvider::class,
]
```

Publish the config file
```
php artisan vendor:publish --provider="Despark\Migrations\Providers\MigrationServiceProvider"
```

You must prepare the database connection to which the library will connect to get the data.
You can create the connection inside `config/database.php`;
Then set the database name that should be used by the library trough `.env` file setting 
`MIGRATION_DATABASE_CONNECTION=your_database_name` key or by setting the database name directly in the published config 
`config/migrations.php`.

#Usage
To be done...