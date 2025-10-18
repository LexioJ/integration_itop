# UX Widgets - Design Specifications and Wireframes

## Overview

This document provides comprehensive design specifications for all user-facing widgets in the iTop Integration app, including layout wireframes, component breakdowns, responsive behavior, icon usage, and theming guidelines.

## CI Preview Widget

### Desktop Layout (≥768px)

```
┌────────────────────────────────────────────────────────────────┐
│  ┌────┐  🔴 [R-000123] Laptop running slow    [Assigned] · 2h  │
│  │Icon│                                                         │
│  │48px│  🏷️ IT Support > Hardware for Boris B. (Demo)         │
│  │    │                                                         │
│  └────┘  🏢 Demo > 👥 IT Team > 👤 Jane Smith                 │
├────────────────────────────────────────────────────────────────┤
│  Description: My laptop has been running very slow since the   │
│  last Windows update. Can someone take a look?                 │
│  [Click to expand/collapse]                                    │
└────────────────────────────────────────────────────────────────┘
```

**Dimensions:**
- Width: 100% of container (fluid)
- Min-width: 300px
- Max-width: 800px
- Padding: 12px
- Icon size: 48x48px
- Gap between elements: 12px

### Mobile Layout (<768px)

```
┌──────────────────────────────┐
│ ┌────┐  🔴 [R-000123]        │
│ │Icon│  Laptop running slow  │
│ │36px│                       │
│ └────┘  [Assigned]           │
│         · 2h ago              │
│                               │
│ 🏷️ IT Support > Hardware    │
│ for Boris B. (Demo)          │
│                               │
│ 🏢 Demo > 👥 IT Team         │
│ > 👤 Jane Smith              │
├───────────────────────────────┤
│ Description: My laptop has... │
│ [Click to expand]             │
└───────────────────────────────┘
```

**Responsive Changes:**
- Icon: 36x36px
- Stack layout (vertical)
- Smaller gaps (8px)
- Wrapped text
- Smaller font sizes

### Component Breakdown

#### Row 1: Title and Status

**Left Side:**
```
Priority Emoji (16px) + Space(4px) + Ticket Link
```

**Components:**
- `<span class="priority-emoji">` - 🔴🟠🟡🟢
- `<a class="ticket-link">` - [R-000123] Ticket Title

**Right Side:**
```
Status Badge + Date
```

**Components:**
- `<span class="status-badge">` - Colored pill
- `<span class="date">` - Relative time

**Styles:**
```scss
.row-1 {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;

  .left {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0; // Allow text truncation
  }

  .priority-emoji {
    font-size: 16px;
    flex-shrink: 0;
  }

  .ticket-link {
    font-weight: 600;
    color: var(--color-main-text);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    &:hover {
      color: #58a6ff;
    }
  }

  .right {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
  }

  .status-badge {
    padding: 2px 8px;
    border-radius: var(--border-radius-pill);
    font-size: 12px;
    white-space: nowrap;
  }

  .date {
    color: var(--color-text-maxcontrast);
    font-size: 12px;
    white-space: nowrap;
  }
}
```

#### Row 2: Breadcrumbs

**Left Side (Service Breadcrumb):**
```
🏷️ Service > Subcategory for Caller (Org)
```

**Right Side (Org/Team/Agent Breadcrumb):**
```
🏢 Org > 👥 Team > 👤 Agent
```

**Styles:**
```scss
.row-2 {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: var(--color-text-maxcontrast);

  .left, .right {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  a {
    color: inherit;
    text-decoration: none;

    &:hover {
      color: #58a6ff;
    }
  }
}
```

#### Description Section

**States:**
- **Collapsed:** 40px max-height, 2 lines visible
- **Expanded:** 250px max-height, scrollable

**Interaction:**
- Click to toggle
- Cursor: pointer
- Tooltip on collapsed: "Click to expand description"

**Styles:**
```scss
.description {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);

  &-content {
    cursor: pointer;
    max-height: 250px;
    overflow: auto;
    color: var(--color-text-maxcontrast);
    white-space: pre-wrap;
    line-height: 1.5;

    &.short-description {
      max-height: 40px;
      overflow: hidden;
      text-overflow: ellipsis;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }
  }
}
```

### Badges and Chips

#### Status Badge

**Design:**
```
┌─────────────┐
│  Assigned   │  ← 2px padding top/bottom
└─────────────┘     8px padding left/right
    ↑
Pill shape (border-radius-pill)
```

**Color Mapping:**

| Status | Background | Text | Usage |
|--------|------------|------|-------|
| New | `rgba(59,130,246,0.15)` | `#3b82f6` | New tickets |
| Assigned | `rgba(139,92,246,0.15)` | `#8b5cf6` | Assigned to agent |
| Pending | `rgba(245,158,11,0.15)` | `#f59e0b` | Waiting for action |
| Resolved | `rgba(40,167,69,0.15)` | `#28a745` | Resolved tickets |
| Closed | `rgba(40,167,69,0.15)` | `#28a745` | Closed tickets |
| Escalated | `rgba(239,68,68,0.15)` | `#ef4444` | SLA breach |

**Implementation:**
```scss
.status-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: var(--border-radius-pill);
  font-size: 12px;
  font-weight: 500;
  text-transform: lowercase;

  &.new {
    background: rgba(59,130,246,0.15);
    color: #3b82f6;
  }

  &.assigned {
    background: rgba(139,92,246,0.15);
    color: #8b5cf6;
  }

  // ... other states
}
```

#### Info Chips (Phase 2 - CIs)

**Design:**
```
┌────────────────┐
│ 📍 Vienna Off. │
└────────────────┘

┌──────────────┐
│ 🏷️ AST-001  │
└──────────────┘
```

**Usage:**
- Location: 📍 + location name
- Asset number: 🏷️ + asset number
- Serial number: #️⃣ + serial number
- Organization: 🏢 + org name

**Styles:**
```scss
.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
  font-size: 12px;
  margin-right: 4px;
  margin-bottom: 4px;

  .icon {
    font-size: 14px;
  }
}
```

### Extras Section (Phase 2 - CI Details)

**Layout:**
```
┌─────────────────────────────────────┐
│ Extras (CI-specific fields)        │
├─────────────────────────────────────┤
│ Brand: Dell                         │
│ Model: Latitude 7420                │
│ CPU: Intel i7-1185G7                │
│ RAM: 16GB                           │
│ OS: Windows 11 Pro                  │
└─────────────────────────────────────┘
```

**Styles:**
```scss
.extras {
  margin-top: 12px;
  padding: 12px;
  background: var(--color-background-hover);
  border-radius: var(--border-radius);

  .extra-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;

    &:last-child {
      margin-bottom: 0;
    }

    .label {
      font-weight: 500;
      color: var(--color-text-maxcontrast);
    }

    .value {
      color: var(--color-main-text);
    }
  }
}
```

## Dashboard Widget

### Layout

```
┌────────────────────────────────────────┐
│ iTop Tickets                      [⚙️] │
├────────────────────────────────────────┤
│ ┌──────────┐ ┌──────────┐ ┌──────────┐│
│ │    5     │ │    12    │ │    2     ││
│ │   New    │ │ Assigned │ │ Overdue  ││
│ └──────────┘ └──────────┘ └──────────┘│
├────────────────────────────────────────┤
│ 🆕 [R-123] New laptop request          │
│ 👥 [I-456] Network outage - Floor 3   │
│ ⏳ [R-789] Printer not working         │
├────────────────────────────────────────┤
│ [View all tickets in iTop]             │
└────────────────────────────────────────┘
```

**Sections:**
1. **Header:** Title + settings gear icon
2. **Stats:** Count cards (new, assigned, overdue)
3. **Recent List:** 3-5 most recent tickets
4. **Footer:** Link to iTop

**Responsive:**
- Desktop: 3 stat cards horizontal
- Mobile: Stack stat cards vertical

### Stat Cards

**Design:**
```
┌──────────┐
│    12    │ ← Large number (24px)
│ Assigned │ ← Label (12px)
└──────────┘
  ↑
Centered text
Min-width: 80px
Padding: 16px
Background: var(--color-background-hover)
Border-radius: var(--border-radius)
```

**Hover Effect:**
```scss
.stat-card {
  cursor: pointer;
  transition: background 0.2s;

  &:hover {
    background: var(--color-background-dark);
  }
}
```

## Search Result Item

### Unified Search Result

```
┌────────────────────────────────────────────────────────────┐
│ [📄] 🔴 🆕 [R-000123] Laptop running slow                  │
│      🟠 P2 • 👤 Jane Smith • 🕐 updated 2h ago            │
│      My laptop has been running very slow since...         │
└────────────────────────────────────────────────────────────┘
```

**Layout:**
- Icon (left): 32x32px ticket icon
- Title (bold): Status emoji + Priority emoji + Ref + Title
- Subline: Priority + Agent + Time + Description snippet

**Responsive:**
- Desktop: Single line title, wrapped subline
- Mobile: Wrapped title, stacked subline

### Smart Picker Suggestion

```
┌────────────────────────────────────────────┐
│ [📄] 🔴 ✅ [R-000123] Laptop running slow  │
│      🏢 Demo • 👤 Jane Smith • 🕐 2h ago   │
└────────────────────────────────────────────┘
```

**Dimensions:**
- Height: 48px
- Padding: 8px 12px
- Icon: 32x32px

**Hover:**
```scss
.suggestion-item {
  cursor: pointer;
  background: var(--color-main-background);

  &:hover {
    background: var(--color-background-hover);
  }

  &.selected {
    background: var(--color-primary-light);
  }
}
```

## Responsive Breakpoints

### Breakpoint Definitions

```scss
// Mobile
@media (max-width: 767px) {
  // Stack layouts
  // Smaller icons (36px → 24px)
  // Larger tap targets (min 44px)
  // Wrapped text
}

// Tablet
@media (min-width: 768px) and (max-width: 1023px) {
  // Medium icons (48px → 36px)
  // 2-column layouts where applicable
}

// Desktop
@media (min-width: 1024px) {
  // Full layouts
  // 48px icons
  // Multi-column where space allows
}
```

### Mobile Optimizations

**Touch Targets:**
- Minimum: 44x44px for all clickable elements
- Spacing: 8px minimum between tap targets

**Font Sizes:**
```scss
// Desktop
.title { font-size: 16px; }
.subline { font-size: 13px; }
.description { font-size: 14px; }

// Mobile
@media (max-width: 767px) {
  .title { font-size: 14px; }
  .subline { font-size: 12px; }
  .description { font-size: 13px; }
}
```

**Scrolling:**
- Enable horizontal scroll for wide breadcrumbs
- Indicate scrollable content with shadows

## Icon Usage

### Ticket Icons

| Icon | File | Usage | Dimensions |
|------|------|-------|------------|
| New Request | user-request.svg | Open user requests | 48x48px |
| Closed Request | user-request-closed.svg | Resolved requests | 48x48px |
| Incident | incident.svg | IT incidents | 48x48px |
| Generic | ticket.svg | Fallback | 48x48px |

### CI Icons (Phase 2)

| Icon | File | Usage |
|------|------|-------|
| 💻 | PC.svg | Computers |
| 📱 | Phone.svg | All phone types |
| 🖨️ | Printer.svg | Printers |
| 💾 | PCSoftware.svg | Desktop software |
| 🌐 | WebApplication.svg | Web apps |
| 📦 | FunctionalCI.svg | Fallback |

### Emoji Icons

**Usage:** When SVG not available or for inline context

**Mapping:**
```javascript
const emojiIcons = {
  PC: '💻',
  Phone: '📱',
  Printer: '🖨️',
  Tablet: '📱',
  PCSoftware: '💾',
  WebApplication: '🌐',
  Location: '📍',
  Organization: '🏢',
  Person: '👤',
  Team: '👥',
  Service: '🏷️',
  AssetNumber: '🔖',
  Status: '📊',
}
```

## Color Schemes and Theming

### Nextcloud Theme Variables

**Use Nextcloud CSS variables for theme compatibility:**

```scss
// Backgrounds
--color-main-background
--color-background-hover
--color-background-dark

// Text
--color-main-text
--color-text-maxcontrast
--color-text-light

// Borders
--color-border
--color-border-dark

// Primary colors
--color-primary
--color-primary-light

// Status colors
--color-success
--color-warning
--color-error

// Border radius
--border-radius
--border-radius-large
--border-radius-pill
```

### Custom Status Colors

**Override only when necessary:**

```scss
.status-badge {
  // Use custom colors for better status differentiation
  &.new { color: #3b82f6; }
  &.assigned { color: #8b5cf6; }
  &.pending { color: #f59e0b; }
  &.resolved { color: #28a745; }
  &.closed { color: #28a745; }
  &.escalated { color: #ef4444; }
}
```

### Dark Mode Support

**Automatic via Nextcloud variables:**

```scss
// Light mode
--color-main-background: #ffffff
--color-main-text: #000000

// Dark mode (automatic)
--color-main-background: #181818
--color-main-text: #e9e9e9
```

**Custom adjustments if needed:**

```scss
@media (prefers-color-scheme: dark) {
  .status-badge {
    // Increase contrast in dark mode
    &.new { background: rgba(59,130,246,0.25); }
  }
}
```

## Animation and Transitions

### Hover Effects

```scss
.clickable {
  transition: background 0.2s ease, color 0.2s ease;

  &:hover {
    background: var(--color-background-hover);
  }
}
```

### Description Expand/Collapse

```scss
.description-content {
  transition: max-height 0.3s ease;

  &.short-description {
    max-height: 40px;
  }

  &:not(.short-description) {
    max-height: 250px;
  }
}
```

### Loading States

```scss
.loading {
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
```

## Accessibility

### ARIA Labels

```html
<button aria-label="Test connection to iTop server">
  Test Connection
</button>

<div role="status" aria-live="polite">
  Connection successful
</div>

<a href="..." aria-label="Open ticket R-000123 in iTop">
  [R-000123] Laptop running slow
</a>
```

### Keyboard Navigation

**Tab Order:**
1. Priority emoji (skip - decorative)
2. Ticket link
3. Status badge (skip - not interactive)
4. Description area (click to expand)

**Keyboard Shortcuts:**
- `Enter` on description → Toggle expand/collapse
- `Escape` on expanded description → Collapse

### Focus States

```scss
.ticket-link:focus {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

.description-content:focus {
  box-shadow: 0 0 0 2px var(--color-primary);
}
```

## Print Styles

```scss
@media print {
  .itop-reference {
    break-inside: avoid;
    page-break-inside: avoid;

    .description-content {
      max-height: none !important; // Always expanded for print
    }

    .status-badge {
      border: 1px solid #000; // Ensure visibility in B&W print
    }
  }
}
```

## References

- **Implementation:** [src/views/ReferenceItopWidget.vue](../src/views/ReferenceItopWidget.vue)
- **Rich Preview Spec:** [rich-preview.md](rich-preview.md)
- **Search Spec:** [unified-search.md](unified-search.md)
- **Nextcloud Design Guidelines:** https://docs.nextcloud.com/server/latest/developer_manual/design/index.html
