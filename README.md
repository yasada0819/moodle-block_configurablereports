# Configurable Reports Block — Chart.js Fork

This is a fork of [jleyva/moodle-block_configurablereports](https://github.com/jleyva/moodle-block_configurablereports) (v5.2.0) with Chart.js support and enhanced template features added.

## About this fork

The `chartjs` branch extends the original plugin with the following features:

- **Chart.js rendering engine** — switchable from the admin settings (pChart / Chart.js)
- **Extended existing graph plugins** — bar, line, and pie charts with new options
- **New graph plugins** — radar, scatter, bubble, tiled chart, and combo chart
- **Template improvements** — support for `##graph:N##` placeholders and a GUI layout builder

For details, see the [Chart.js branch](#chartjs-branch) section below.

> **Note**: The `MOODLE_4x_STABLE` branch is kept in sync with the upstream repository. All custom additions are in the `chartjs` branch.

---

## chartjs branch

### Requirements

- Moodle 4.1 or later (Moodle 4.5+ recommended)
- PHP 8.1+
- Chart.js is bundled with Moodle core (`core/chartjs`) — no separate installation needed

### Installation

1. Clone or download the `chartjs` branch
2. Copy files to `blocks/configurable_reports/` in your Moodle installation
3. Visit `/admin/index.php` to complete the upgrade

### Settings

Navigate to **Site administration → Plugins → Blocks → Configurable Reports**

| Setting | Options | Description |
|---|---|---|
| Graph library | pChart (default) / Chart.js | Rendering engine for all graph plugins |
| Template editor | Classic (default) / GUI builder | Editor mode for the template component |

### New and extended graph plugins

#### Extended plugins

| Plugin | New options |
|---|---|
| Bar | Direction (vertical/horizontal), grouping (grouped/stacked), histogram mode, reverse datasets |
| Line | Y1/Y2 dual axis, group column (optional), smooth curves, fill area |
| Pie | Doughnut style, width/height settings |

#### New plugins

| Plugin | Description |
|---|---|
| Radar | Radar/spider chart (Chart.js only) |
| Scatter | Scatter plot with optional color grouping by label column |
| Bubble | Bubble chart with r-value scaling (auto/manual) |
| Tiled chart | Small multiples — bar, line, area, pie, doughnut, or radar tiled by group |
| Combo | Combined bar + line chart with optional dual Y axis |

### Template improvements

#### Placeholders

The following placeholders are available in the template header and footer:

| Placeholder | Description |
|---|---|
| `##graphs##` | All graphs in a single column |
| `##graph:0##` | First graph only |
| `##graph:N##` | Nth graph (zero-indexed, dynamic) |
| `##reporttable##` | Data table |
| `##reportname##` | Report name |
| `##reportsummary##` | Report summary |
| `##exportoptions##` | Export buttons |
| `##calculationstable##` | Calculations table |
| `##pagination##` | Pagination |

**Example — two graphs side by side (header):**
```html
<div style="display:flex; gap:16px;">
  <div style="flex:1;">##graph:0##</div>
  <div style="flex:1;">##graph:1##</div>
</div>
```

#### GUI builder

When **Template editor** is set to **GUI builder**, a row-and-column layout editor is available. Each cell can contain a placeholder or custom HTML. The layout is stored as JSON and can be re-edited at any time.

### Acknowledgements

This fork was developed with the assistance of [Claude](https://claude.ai) (Anthropic), 
an AI assistant, for code generation and design discussion.
All code has been reviewed and tested by the author.

---

## Original README

### Configurable Reports Block

Installation, Documentation, Tutorials...
See http://docs.moodle.org/en/blocks/configurable_reports/
Also http://moodle.org/mod/data/view.php?d=13&rid=4283

**Author**: Juan Leyva
http://twitter.com/jleyvadelgado

**Thanks to:**
- Sara Arjona for being a co-maintainer
- Nadav Kavalerchik for developing amazing new features
- Ivan Breziansky for translating the block to slovak language
- Iñaki Arenaza for translating the block documentation to spanish
- Luis de Vasconcelos for testing the block
- Adam Olley and Netspot Moodle Partner for improving some parts of the Moodle2 version

Some functionalities of this plugin uses code from:

**Admin Report: Custom SQL queries**
http://moodle.org/mod/data/view.php?d=13&rid=2884
By Tim Hunt

### Version history

#### 4.5.0 (2024051300) for Moodle 4.5
Release date: 06.10.2024

**What's Changed**
- fix webservice calls when missing user by @danielneis
- Issue with downloading a filtered report by @luukverhoeven
- Apply format_string on the breadcrumb navigation by @golenkovm
- csv delimiter settings by @Tsheke
- Set current courseid when importing a custom SQL report by @reskit
- Fixes #147 by @marcelorhmaia
- User Profile Fields Language Filter Fix by @sameer-ah
- Fix 201: Process report names / filenames through Moodle filters by @michael-milette
- FIX-226: Stop getting string from report_customsql plugin by @michael-milette
- Set BOM for CSV export closes #198 by @mvaraujo
- Provide support for SYLK export #200 by @mvaraujo
- Fix issue #62 Error with apostrophe by @TomoTsuyuki
- Export multilanguage support by @Tsheke
- Add embed options by @keevan
- fix variable redeclaration by @danielneis

#### 4.1.0 (2023120600) for Moodle 4.1
Release date: 06.12.2023

- Reformat code
- Add CI testing based on Github Actions created by Catalyst IT
- Add PHP 7.4 support - minimum PHP version is now 7.4
- Add PHP 8.0 / 8.1 support
- pChart library updated to version 2.4.0
- JS tablesorter library updated to version 2.31.3
- JS CodeMirror library updated to version 5.65.16
- Move repository to https://github.com/Lesterhuis-Training-en-Consultancy/moodle-block_configurablereports

Thanks to Lesterhuis Training & Consultancy for the contribution / updated by Ldesign Media

#### 3.9.0 (2019122000) for Moodle 3.4 – 3.9
Release date: Tuesday, 3 November 2020

- Added matching colors to pie charts
- Added unmapped palette for general colors to pie charts
- Added webservice to get reports data
- Added new filters for competencies
- Other fixes and improvements

Thanks Alex Rowe, David Saylor, Michael Gardener, Muhammad Osama Arshad, Daniel Poggenpohl, Daniel Neis, François Parlant and all the contributors.

#### Earlier versions

See the original repository for full version history:
https://github.com/jleyva/moodle-block_configurablereports
