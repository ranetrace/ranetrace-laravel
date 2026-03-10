## Brainstorm Tasks

- ~~Read the report at @spec/investigate/reports/stacktrace-processing-improvements.md. Our goal now is to create the final actionable plan for improving stacktrace processing in this package and how we send that data to Sorane over the API.~~ **COMPLETED**

## Progress

- **2026-01-20**: Completed stacktrace processing implementation plan
  - Conducted detailed interview covering 18 technical decisions
  - Created comprehensive report at `spec/brainstorm/reports/stacktrace-processing-implementation-plan.md`
  - Key decisions made:
    - Replace `trace` string with structured `frames` array (no backward compat)
    - Smart truncation: 30 app frames max, 50 total frames max
    - `in_app` boolean classification for each frame
    - Relative file paths (base_path stripped)
    - Adjacent vendor frame preservation during truncation
    - Metadata fields for truncation counts (total_frames, omitted_frames, etc.)
    - ErrorCapture service class architecture
    - App frames only fingerprinting for error grouping
    - Conservative field length limits (file: 500, function: 200, class: 300)
    - JSON column storage for frames in database 
