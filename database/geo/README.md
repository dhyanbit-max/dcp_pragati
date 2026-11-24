# Geodata for development

`assam-state-unofficial.geojson` is the Assam state outline used to highlight the state on the works map
in development and demos. It is **unofficial**:

- Source: DataMeet India community / Election Commission of India, via geoBoundaries (gbOpen, IND ADM1, simplified).
- Licence: Creative Commons Attribution 2.5 India (CC BY 2.5 IN). Attribution is shown on the map.

Before go-live, load the official boundary from Survey of India, Bhuvan or the T&D Department:

```bash
php artisan geo:import-boundaries state assam-official.geojson --property=<code field> --source="<authority, edition>"
```
