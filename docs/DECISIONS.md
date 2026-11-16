[NOTE] this is not AI generated

# Seats and intermediate stops

### Can a seat vacated at an intermediate stop be resold for the remainder of the trip?

---

YES, as long as the time and driver's location allows for it:
- the driver can resell the seat to a new passenger starting from the stop where previous passenger left or after it.
- the new passenger picking time shouldnt desrupte the whole trip schedule.

# Pricing

### How is the price of a trip segment calculated?

---

the price is calculated based on the distance and a value (price per km) plus an negotiation margin decided by rating:

- the price per km is a const determined by admin (e.g 1.5dh per km).
- negotiation margin is 5% of the distance * km price + 1% for each point in rating.
- distance is the distance of the trip segment.

Price = (Distance in km * Price per km) + (Distance in km * Price per km * (0.05 + rating * 0.01))

# modifications

### What happens when a driver modifies a departure time or removes a stop after bookings have already been made?

---

less then 24 hours before departure:
- driver cant modify the trip what so ever
- he can only cancel it and get a penality of -1 rating
- passengers gets full refund

more then 24 hours before departure:
- driver can modify the trip
- passengers get notified about the modification and have thier seats pending until they confirm the modification
- passengers can request refund and get full refund in case they refuse the modification

# Booking

### Can a passenger book multiple seats at once?

---

Yes, passengers can book multiple seats at once, as long as they pay for all of them even if they didnt show up for the trip.

# Reviews

### Who reviews whom, and when?

---

both drivers and passengers can review each other after the trip is completed
both types of accounts start with 10 ratings of value 4 which seats them at stable tier.
