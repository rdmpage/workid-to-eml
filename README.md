# Work identifier to Ecological Metadata Language (EML) file

Create a stub Ecological Metadata Language (EML) file based on the identifier for a work, such as a DOI.

Usage: `php workid-to-eml.php --doi=<doi-for-paper>`

Intended use is if you are creating a GBIF dataset based on data in a publication, and you want an EML file that credits the paper as the source of the data. The EML file uses the title of the paper as the title of the dataset, adds the authors as creators (with their ORCIDs, if available), and cites their work. It attempts to use the same license for the data as the source work, if it is an open license.

EML files are huge, complicated beasts. The XML Schema Definition (XSD) file that GBIF follows is included in this repository. My code outputs a tiny subset of that, typically enough to parse the GBIF validator.

The code requires access to the Internet to lookup metadata for the DOI, which it retreives using content negotiation by setting the header of the HTTP GET request to include `Accept: application/vnd.citationstyles.csl+json`. This works well for DOIs issued by CrossRef, your mileage with other DOI agences (e.g., DataCite) may vary.

Note that in creating a dataset based on published work, you have the role of `<metadataProvider>` and `<contact>`. For now these are hard-coded to be me.


