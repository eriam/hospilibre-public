# Welcome to the Sample Module !

The primary objective of this module is to provide developers with an exhaustive set of examples that will use all the back-end & front-end capabilities of the OX Framework.

More than a catalog of components, we chose the management of a digital film platform as a functional scope, then we wrote user stories in order to provide implementation of the components and thus illustrate the possibilities of the framework for educational purposes.

# Manifest
- Provide quality, documented and tested code
- Have the most up-to-date code possible, by continuously integrating new components and new features
- Respect our "best practice" and having this module documented over time in the project [wiki](https://gitlab.com/openxtrem/mediboard/-/wikis/home), [ARD's](dev/ADR) and [story book](https://openxtrem.gitlab.io/oxify/)
- Used to reflect the OX design system

# Data formats
- All the API use the [JSON:API format](https://jsonapi.org/format)
- All the dates, datetimes and times are strings in the following formats :
  - **DateTimes** : `YYYY-MM-DD H:i:s`
  - **Dates** : `YYYY-MM-DD`
  - **Times** : `H:i:s`
- Fields of type **Set** (enums with multiple values) are string with their differents values separated by a pipe (|).

# Class diagram of the entities
![Class diagram](modules/sample/resources/Images/classes_diagram.svg)
