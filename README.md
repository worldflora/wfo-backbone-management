# wfo-backbone-management

This application acts as the backend for the Rhakhis taxonomic editor. It provides three main areas of functionality:

1. A GraphQL API that is used by graphical user interface available to taxonomists to edit their data. The actual ui is managed in a separate repository (wfo-backbone-ui). 
2. Some simple forms used by the TENs manager for bulk import of data
3. Scripts to publish the twice yearly WFO Plant List data releases.


## Notes on install and upgrade

```
cd rhakhis/ui/wfo
rm -rf build/
npm update
npm build
```

Should build OK. Then you can:

```
cd  /var/wfo-list/rhakhis 
./