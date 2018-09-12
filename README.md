# RePEc for Drupal 8

RDF integration with [Research Papers in Economics](http://repec.org/)
(RePEc) for Drupal 8.

This module is a port of the Drupal 7 version, that is limited to 
the [_Working Paper_ template](https://ideas.repec.org/t/rdfintro.html).

## Configuration

- [Obtain a RePEc archive handle](https://ideas.repec.org/t/archivehandle.html)
- Configure the system wide settings (/admin/config/repec/settings)
that are used for the archive template (*arch.rdf file)
- Activate RePEc for each desired content type and map the fields,
it will produce the series template (*seri.rdf file)
- Working Paper templates are then generated/updated while saving entities.

## Roadmap

- Provide an option per content type to create templates based on a per entity
flag (use case: share with RePEc a subset of entities, not all of them).

The current version has limitations, so the following improvements
can be considered:

- Currently restricted to content types, open to other entity types
- Currently the rdf generation/update is done while saving the entities,
provide an option to create rdf files for existing entities
while enabling a content type
- Currently limited to RePEc Working Paper, could be opened to 
[other types](https://ideas.repec.org/t/rdfintro.html):
journal, book, book chapter, software. 
