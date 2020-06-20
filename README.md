# `zmq-fileserver`

This is a simple fileserver written in PHP (7.4+) using [ZeroMQ](https://zeromq.org/).  
It requires `ext-zmq` and PHP7.4+.  

There are three executable files:
- `$ bin/server` runs the fileserver
- `$ bin/command <arg1> <arg2> ...` sends a command to the server
- `$ bin/query <arg1> <arg2> ...` sends a query to the server and returns the response

## Commands

- `SAVE <namespace> <name> <contents>` saves a file
- `DELETE <namespace> <name>` deletes a file if it exists
- `DELETE_ALL <namespace>` deletes an entire namespace

To save a file to the fileserver you can do:  
`$ bin/command SAVE my-project-namespace 1.html "<!DOCTYPE html><html>...</html>"`  
`$ bin/command SAVE my-project-namespace 2.xml "$(cat some/xml/file)"`  

## Queries

- `CONTAINS <namespace> <name>` returns `Y` if the file exists in the namespace, else `N`
- `LOAD <namespace> <name>` returns the file contents if it exists, else `-1`

To load a file from the fileserver you can do:  
`$ bin/query LOAD my-project-namespace 1.html`

## Configuration

See `config/config.ini`. It contains an example configuration.
