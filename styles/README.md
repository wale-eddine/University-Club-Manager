## CSS Modules Breakdown

## Recent UI Updates (April 2026)

 - Carousel indicators are now hidden globally.
 - Sorting headers in data tables are interactive and asynchronous (no full page reload).
 - New shared script used by sortable date tables: `js/sortable-table.js`.

### Foundation & Variables
- **`variables.css`** - CSS custom properties, font imports, global reset, and base body/html styles

### Layout
- **`layout.css`** - Main container, layout structure, and responsive grid system

### Header & Navigation
- **`header-nav.css`** - Header, navigation bar, brand logo, dropdowns, profile menu, footer, and all animations (profilePulse, notifDotPulse)

### Components
- **`buttons.css`** - All button styles (primary, outline, grey-press) with hover effects and animations
- **`forms.css`** - Form elements, input fields, labels, textareas, and select styling
- **`alerts.css`** - Alert boxes (success, danger, info)
- **`cards.css`** - Card components, grids, card-footers, and club/event card layouts
- **`carousel.css`** - Carousel/slider components with navigation arrows and animations (indicator dots hidden)
- **`badges.css`** - Badge components (primary, success)
- **`search.css`** - Search box styling
- **`tables.css`** - Table styling with headers, rows, hover effects, and sortable header link styles

### Page Templates
- **`dashboard.css`** - Dashboard sections, page titles, action buttons, empty states, and detail sections
- **`modals-popups.css`** - Custom modals, membership decision popups, delete confirmations, and danger buttons
- **`notifications.css`** - Decision bell icon, notification counts, empty notification modals, and animation effects

### Responsive Design
- **`responsive.css`** - All media queries (@media max-width: 768px, 992px) for mobile/tablet optimization

## File Organization Benefits

✓ **Easier Maintenance** - Changes to specific components don't affect the entire stylesheet
✓ **Better Performance** - Load only needed styles
✓ **Scalability** - Easy to add new modules for future features
✓ **Team Collaboration** - Different team members can work on different modules without conflicts
✓ **Reduced Redundancy** - Clear separation of concerns

## Import Order

The stylesheets are imported in this specific order in all HTML files:
1. Bootstrap CSS (external)
2. variables.css (foundation)
3. layout.css (structure)
4. header-nav.css (header/footer)
5. buttons.css (interactive)
6. forms.css (inputs)
7. alerts.css (notifications)
8. cards.css (content containers)
9. carousel.css (sliders)
10. badges.css (labels)
11. search.css (search)
12. tables.css (data display)
13. dashboard.css (page layouts)
14. modals-popups.css (dialogs)
15. notifications.css (bells/notifications)
16. responsive.css (mobile/tablet rules)

## Updated HTML Files

All 15 HTML files have been updated to link to these modular CSS files:
- html pages/index.html
- html pages/dashboard.html
- html pages/requests.html
- html pages/club/clubs.html
- html pages/club/club_create.html
- html pages/club/club_detail.html
- html pages/club/club_edit.html
- html pages/club/club_requests.html
- html pages/event/events.html
- html pages/event/event_create.html
- html pages/event/event_detail.html
- html pages/event/event_edit.html
- html pages/profile/login.html
- html pages/profile/profile.html
- html pages/profile/register.html