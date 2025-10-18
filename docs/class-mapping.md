# Class Mapping and Preview Field Sets

This document defines the canonical output_fields used by the Unified Search, Smart Picker, and Rich Preview widgets for each supported iTop class.

## 1) Common core fields (for previews)
Applies to all classes listed below:
- id
- name
- finalclass
- org_id_friendlyname
- status
- business_criticity
- location_id_friendlyname
- move2production
- asset_number
- serialnumber
- brand_id_friendlyname
- model_id_friendlyname
- last_update
- description

These fields provide: title, status badge, organization/location chips, identifiers (asset/serial), brand/model, and a short description with update time.

## 2) Class-specific extras (for previews)
- PC
  - type, osfamily_id_friendlyname, osversion_id_friendlyname, cpu, ram
- Phone
  - phonenumber
- IPPhone
  - phonenumber
- MobilePhone
  - phonenumber, imei
- Tablet
  - —
- Printer
  - —
- Peripheral
  - —
- PCSoftware (SoftwareInstance)
  - system_name, software_id_friendlyname, softwarelicence_id_friendlyname, path, move2production
- OtherSoftware (SoftwareInstance)
  - system_name, software_id_friendlyname, softwarelicence_id_friendlyname, path, move2production
- WebApplication
  - url, webserver_name

## 3) Lightweight list fields (for Unified Search rows)
Common minimal set:
- id, name, finalclass, org_id_friendlyname, status, asset_number, serialnumber, last_update

Per-class additions:
- PC: type
- Phone/IPPhone/MobilePhone: phonenumber
- WebApplication: url
- PCSoftware/OtherSoftware: system_name

Rationale: reduce payload and speed up search responses; full preview expands on demand when a link is pasted or an item is opened.

## 4) Example output_fields strings

Use comma-separated lists as `output_fields` in `core/get` requests.

- Preview (common core):
```
id,name,finalclass,org_id_friendlyname,status,business_criticity,location_id_friendlyname,move2production,asset_number,serialnumber,brand_id_friendlyname,model_id_friendlyname,last_update,description
```

- PC preview extras:
```
,type,osfamily_id_friendlyname,osversion_id_friendlyname,cpu,ram
```
- Phone/IPPhone preview extras:
```
,phonenumber
```
- MobilePhone extras:
```
,phonenumber,imei
```
- PCSoftware/OtherSoftware extras:
```
,system_name,software_id_friendlyname,softwarelicence_id_friendlyname,path,move2production
```
- WebApplication extras:
```
,url,webserver_name
```

- List (common minimal):
```
id,name,finalclass,org_id_friendlyname,status,asset_number,serialnumber,last_update
```
- List additions per class as noted above.

## 5) DTO mapping hints (PreviewMapper)
- title: name (fallback: friendlyname if needed)
- subtitle: [finalclass label] • [org_id_friendlyname]
- badges: status, business_criticity
- chips: location, asset_number, serialnumber, brand/model
- extras area: per-class extras (e.g., PC: type/OS; WebApp: url; Phone: phonenumber)
- timestamps: last_update (and move2production when present)

This mapping keeps the widget consistent across classes while surfacing the most relevant attributes per type.