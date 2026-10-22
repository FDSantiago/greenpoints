# Build GreenPoints, step by step

This guide walks you through the project in the order I'd build it yourself. Each lesson names the file to read, the idea it teaches, and what to try. The code is commented, so read it side by side with this guide.

## 0. Run it

```bash
# Terminal 1: the Laravel backend (from greenpoints-backend/)
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # creates the demo users, rewards, waste types
php artisan serve                   # http://localhost:8000

# Terminal 2: this project
npm install
npm run dev                         # http://localhost:5173
```

Demo logins (password is `password`): `student1@greenpoints.test`, `staff@greenpoints.test`, `admin@greenpoints.test`.

## 1. What is (and isn't) built

I only built screens your backend can serve. Anything else would be a button that goes nowhere.

| Your spec | Status | Why |
|---|---|---|
| Home / dashboard | Built | `GET /dashboard` + `/waste-submissions/summary` |
| Waste submission | Built | `POST /waste-submissions` |
| Drop-off history + details | Built | `GET /waste-submissions`, `/{id}` |
| My points / wallet | Built | `GET /wallet`, `/wallet/transactions` |
| Rewards catalog + redemption + pickups | Built | `/rewards`, `/rewards/{id}/redeem`, `/redemptions` |
| Leaderboard | Built | `GET /leaderboard` |
| Environmental impact | Built as "My impact" | monthly summary endpoint |
| Notifications | Built | `/notifications*` |
| Login, Registration | Built | `/auth/login`, `/auth/register` |
| Admin overview and reports | Built (one screen) | `/admin/reports/impact*` |
| User, group, waste type, reward management | Built | `/admin/*` |
| Submission review, reward pickup (staff) | Built | `/staff/*` |
| Upload photo, drop-off location | **Skipped** | The API has neither (only a notes field) |
| Profile editing, change password, profile picture | **Skipped** | No update endpoint for yourself. Name, email and group show in the avatar menu |
| Forgot password | **Skipped** | No reset endpoints |
| Help / About, Splash | **Skipped** | Static content, not backend work. Easy to add as a plain page later |
| Category filter on rewards, total kg all-time | **Skipped** | API doesn't provide them |
| Group picker when registering | **Skipped** | `group_id` is accepted but no public endpoint lists groups, so an admin assigns the group |

## 2. The five ideas that run the whole app

1. **The Vite proxy** (`vite.config.js`) forwards `/api` to Laravel. The browser sees one origin, so no CORS pain in development.
2. **One `api()` function** (`src/lib/api.js`) adds the token, sends JSON, turns errors into an `ApiError`, and logs you out on 401.
3. **One `useApi()` hook** (`src/hooks/useApi.js`) is "GET this URL and give me `{ data, loading, error, reload }`".
4. **`AuthContext`** (`src/auth/`) keeps the logged-in user and the token. `RequireAuth` guards routes by role.
5. **Every page is the same shape:** call `useApi`, show a spinner or error, render the data. Actions call `api()` then `reload()`.

## 3. Lessons

### Lesson 1: Design tokens and the shell (`styles.css`, `Layout.jsx`)
All colours live in `:root` variables at the top of `styles.css`. Change `--green` and the whole app changes. `Layout.jsx` shows a top bar with pill links on desktop (900px and up) and a bottom tab bar on phones. It is one component and one CSS media query, with no duplicate pages.
**Try:** change `--green` to blue, then move the breakpoint in `@media (min-width: 900px)`.

### Lesson 2: Talking to the server (`lib/api.js`)
Read it top to bottom. Three things to notice:
- `params` skips empty values, so `{ status: '' }` never becomes `?status=`.
- A 204 (delete) has no body, so it returns `null`.
- `ApiError.details` flattens Laravel's `{ errors: { email: ["..."] } }` into a list you can show.

### Lesson 3: Loading data (`hooks/useApi.js` and `components/ui.jsx`)
`useApi('/wallet', { page })` re-fetches whenever the path or params change. The `cancelled` flag ignores slow, outdated responses (otherwise fast clicking on filters shows the wrong list). `<Async state={...}>` in `ui.jsx` handles loading and error, so pages stay short.

### Lesson 4: Login and route guards (`auth/`, `Login.jsx`, `App.jsx`)
- Login returns `{ token, user }`. We save the token in `localStorage` and the user in state.
- On refresh, `AuthProvider` calls `/me` to restore the user.
- `<RequireAuth roles={['admin']} />` wraps a group of routes in `App.jsx`. This only hides screens. The server's middleware is the real lock.
- **Gotcha:** `/me` returns `{ data: user }`, but login returns `user` unwrapped. Laravel only wraps a Resource when it is the whole response.

### Lesson 5: Your first real page (`Home.jsx`)
Two `useApi` calls run in parallel. The hero card shows `points.balance` and the progress bar uses the server's `progress_percent`. The page never calculates points itself.
**Try:** add a card that shows `d.rank.group.points`.

### Lesson 6: Forms that build a payload (`DropOff.jsx`)
State is an object keyed by category id. The live estimate uses the same formula as the server (`weight × points_per_kg × multiplier`), but the server **always recalculates**. That's why the label says "Estimated". Validation errors (422) appear through `ErrorBox`.
**Try:** show per-item estimated points next to each weight field.

### Lesson 7: Lists, filters, pagination (`History.jsx`, `Wallet.jsx`, `Pager`)
Filter state (`status`, `page`) lives in `useState` and is passed into `useApi`. Remember to reset `page` to 1 when a filter changes. Laravel's paginator gives `current_page` and `last_page`, which `Pager` uses.

### Lesson 8: Actions that spend points (`RewardDetail.jsx`)
Double-taps and flaky Wi-Fi can charge twice. The fix is the `Idempotency-Key` header: generate one UUID per press, **reuse it on retry**, and clear it on success. The server replays the original result instead of charging again. Also see `MyPickups.jsx` for cancel and refund.

### Lesson 9: Notifications (`Layout.jsx` bell, `Alerts.jsx`)
The bell polls `/notifications/unread-count` every 45 seconds, which is cheap. The `style` field (`info`, `alert`, `goal`) becomes a CSS class, so the three card looks cost nothing. Tapping a notification marks it read and uses `link` to navigate.

### Lesson 10: Staff tools (`staff/Review.jsx`, `staff/Pickups.jsx`)
Review cards hold editable copies of the weights. "Verify" sends only what staff typed, and the server awards the points. Rules you'll see as 403/409 errors: staff can't review their own drop-off, and finished items can't be changed. `ErrorBox` shows the server's message as-is.

### Lesson 11: Admin without repeating yourself (`admin/Crud.jsx`)
Groups, waste types and rewards are the same screen: a table, an add/edit modal and a delete. `CrudPage` takes a small config (`path`, `fields`, `cols`) and does the rest. Adding a new managed resource is about 10 lines. `Users.jsx` is separate because it has search, filters, pagination and a points-adjustment form. `Overview.jsx` also shows how to download a CSV that needs an auth header: fetch a blob, then click a temporary link.

## 4. Screen to endpoint map

| Screen | File | Endpoints |
|---|---|---|
| Login / Register | `Login.jsx`, `Register.jsx` | `POST /auth/login`, `/auth/register`, `GET /me` |
| Home | `Home.jsx` | `GET /dashboard`, `/waste-submissions/summary` |
| Drop off | `DropOff.jsx` | `GET /waste-categories`, `POST /waste-submissions` |
| History | `History.jsx`, `SubmissionDetail.jsx` | `GET /waste-submissions`, `/waste-submissions/{id}` |
| Wallet | `Wallet.jsx` | `GET /wallet`, `/wallet/transactions` |
| Rewards | `Rewards.jsx`, `RewardDetail.jsx` | `GET /rewards`, `/rewards/{id}`, `POST /rewards/{id}/redeem` |
| My pickups | `MyPickups.jsx` | `GET /redemptions`, `POST /redemptions/{id}/cancel` |
| Leaderboard | `Leaderboard.jsx` | `GET /leaderboard` |
| My impact | `Impact.jsx` | `GET /waste-submissions/summary?month=` |
| Alerts | `Alerts.jsx` | `GET /notifications`, `POST .../read`, `read-all`, `DELETE` |
| Staff review | `staff/Review.jsx` | `GET /staff/submissions`, `POST .../verify`, `.../reject` |
| Staff pickups | `staff/Pickups.jsx` | `GET /staff/redemptions`, `POST .../ready`, `.../claim` |
| Admin overview | `admin/Overview.jsx` | `GET /admin/reports/impact`, `.../history`, `POST .../generate`, `GET .../export` |
| Admin users | `admin/Users.jsx` | `/admin/users*`, `/admin/users/{id}/points-adjustments`, `/admin/groups` |
| Groups / Waste types / Rewards | `admin/Crud.jsx` | `/admin/groups`, `/admin/waste-categories`, `/admin/rewards` |

## 5. Gotchas

- **Wrappers:** most responses are `{ data: ... }`. Rewards also has `balance` beside `data`. Paginated lists are the paginator itself (`p.data` is the array).
- **422** means field errors (show `error.details`). **409** means "can't do that now" (out of stock, already processed). Both come back with a readable `message`.
- **Deleting** something with history returns 409. Deactivate it instead. Users has a Deactivate button for this.
- **Roles:** admins are also staff, so admins can open the review and pickup screens.

## 6. Going to production

1. Set `VITE_API_URL=https://your-api.example/api/v1` and run `npm run build`.
2. Serve `dist/` with a rewrite of every path to `index.html` (so `/wallet` works on refresh).
3. In Laravel, allow your site's origin in `config/cors.php` (run `php artisan config:publish cors` first if the file is missing). You use Bearer tokens, so `supports_credentials` can stay `false`.

## 7. Ideas for after you finish

- Add a Help page (static) explaining how to sort waste.
- Ask your backend teammate for: forgot-password, update-profile, and a public `GET /groups` so registration can pick one.
- Replace the emoji icons in `lib/format.js` with real artwork. Rewards already show `image_url` once an admin sets one.
