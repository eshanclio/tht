The content of the "Parking Lot" coding challenge requirement document has been extracted and formatted into the Markdown structure below:

# Roofr Coding Challenge: Parking Lot

## Summary

Urban parking is a deceptively complex domain. This challenge is designed to see how you approach building a backend system from scratch, how you structure your solution, where you draw layer boundaries, and how you communicate tradeoffs. You will build a set of API endpoints to power a parking lot management system.

We are evaluating how you think, how you structure a system, and how clearly you can articulate your decisions.

---

## The Problem

A parking lot needs to track available spots and manage vehicles as they park and depart. The lot accommodates three types of vehicles:

* **Motorcycles:** Can park in any available spot (motorcycle or regular).
* **Cars:** Can only park in a regular spot.
* **Vans:** Require three consecutive regular spots.

---

## Required Capabilities

Design and implement an API that supports the following:

1. **Park a vehicle:** Assign a spot (or spots) to an arriving vehicle.
2. **Unpark a vehicle:** Free all spots associated with a departing vehicle.
3. **Lot availability:** Return the current state of the lot, including available spots per vehicle type, total capacity, and a breakdown by spot type.

---

## Requirements

### 1. Parking Spot Rules

Assignments must be rejected if the vehicle violates the rules below:

| Vehicle | Motorcycle Spot | Car Spot |
| --- | --- | --- |
| **Motorcycle** | ✅ (1 spot) | ✅ (1 spot) |
| **Car** | ❌ | ✅ (1 spot) |
| **Van** | ❌ | ✅ (3 consecutive spots) |

### 2. Lot Structure & Constraints

* **Seeding:** The lot should be seeded with a fixed set of motorcycle and car spots. Dynamic configuration is not required.
* **Spatial Constraints:** The lot must be organized into distinct sections or rows. Vehicles requiring multiple spots (vans) must find them within the **same section**.
* **Vehicle Identity:** Each vehicle has a unique identifier (e.g., license plate) provided by the camera system to track occupancy and departure.
* **Atomicity:** Van reservations for three consecutive spots must be handled as a single atomic operation. Partial reservations are invalid.
* **No Availability:** If no eligible spot exists, the request should be rejected with a clear, descriptive error.
* **Scalability:** The solution must support multi-tenancy for use across multiple parking lots.

---

## Architecture & Tech Stack

* **Tech Stack:** Base setup uses **Laravel** and **PostgreSQL**. You may extend or swap these, but be prepared to explain why.
* **Layered Architecture:** Keep HTTP handling, business logic, and persistence clearly separated.
* **Typed Data Flow:** Use DTOs, value objects, and typed response structures.
* **Deliberate Patterns:** Use intentional patterns that you can defend during the review.

---

## Test Case Examples

Your submission should include tests (unit, integration, or both) demonstrating core behavior. Key scenarios include:

* **Motorcycle Fallback:** A motorcycle taking a car spot when motorcycle spots are full.
* **Car Blocked:** A car being rejected because only motorcycle spots remain.
* **Van Success:** A van atomically claiming three consecutive car spots.
* **Van Rejection (Fragmentation):** A van being rejected if car spots are available but not consecutive.
* **Van Departure:** Freeing all three spots simultaneously upon departure.

---

## Instructions

1. **Time:** Allocate approximately 1-2 hours. A well-structured partial solution is better than a shallow complete one.
2. **AI Usage:** You are encouraged to use AI tools, but you must be able to defend and explain every decision made in your code.
3. **Documentation:** Document your assumptions, tradeoffs, and alternative approaches considered.
4. **Submission:** Send a ZIP archive of the `/src` directory to your talent representative.
