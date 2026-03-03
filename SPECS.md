# Decriptat Public Jobs (WP) - MVP

## Goal
Create a WordPress plugin that adds a public-sector jobs section to decriptat.ro.

## Requirements
1) Register a Custom Post Type: public_job
   - public, has archive, supports: title, editor, excerpt, thumbnail
2) Register taxonomies:
   - institution (hierarchical = false)
   - job_category (hierarchical = true)
3) Register post meta (show_in_rest=true):
   - source_url (string)
   - published_date (string or date)
   - deadline (string or date)
   - location (string)
   - is_it (boolean)
4) Frontend templates:
   - templates/archive-public_job.php: list jobs, newest first, with fields + link to single
   - templates/single-public_job.php: job detail with “Vezi anunțul oficial” button (source_url)
   - include empty-state if no jobs exist
5) Add 2 shortcodes:
   - [decriptat_joburi_it] => list only jobs where is_it=1
   - [decriptat_joburi_toate] => list all jobs
6) Keep code simple, clean, with prefix decriptat_pj_.
7) No crawling / no Python yet.