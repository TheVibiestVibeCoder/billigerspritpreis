
Swagger UIswagger
Spritpreisrechner

 2.0 

[ Base URL: api.e-control.at/api ]

https://api.e-control.at/sprit/1.0/api-docs?group=public-api
Spritpreisrechner Beschreibung
E-Control - Website
Send email to E-Control
monitoring
Geo Sync Monitoring Controller
GET
/monitoring
Gets the result of the last synchronization
ping
Ping Rest Controller
GET
/ping
Returns a welcome message and current time of the application
regions
Regions Rest Controller
GET
/regions
Delivers all possible regions that can be used for the region search
GET
/regions/units
Delivers all possible administrative units with coordinates
search
Search Rest Controller
GET
/search/gas-stations/by-address
Searches for gas stations at the given location
GET
/search/gas-stations/by-region
Searches for gas stations at the given region
Models
BezirkDTO{
c	integer($int64)
g	[GemeindeDTO{...}]
n	string
}
BundeslandDTO{
b	[BezirkDTO{...}]
c	integer($int64)
n	string
}
Contact{
fax	string
mail	string
telephone	string
website	string
}
GasStationPublic{
contact	Contact{
fax	string
mail	string
telephone	string
website	string
}
distance	number($double)
id	integer($int64)
location	Location{
address	string
city	string
latitude	number($double)
longitude	number($double)
postalCode	string
}
name*	string
offerInformation	OfferInformation{
selfService	boolean
service	boolean
unattended	boolean
}
open	boolean
openingHours	[OpeningHour{...}]
otherServiceOffers	string
paymentArrangements	PaymentArrangements{
accessMod	string
clubCard	boolean
clubCardText	string
cooperative	boolean
}
paymentMethods	PaymentMethods{
cash	boolean
creditCard	boolean
debitCard	boolean
others	string
}
position	integer($int32)
prices	[...]
}
GemeindeDTO{
b	number
l	number
n	string
p	string
}
Location{
address	string
city	string
latitude	number($double)
longitude	number($double)
postalCode	string
}
OfferInformation{
selfService	boolean
service	boolean
unattended	boolean
}
OpeningHour{
day*	stringEnum:
[ MO, DI, MI, DO, FR, SA, SO, FE ]
from	string
to	string
}
PaymentArrangements{
accessMod	string
clubCard	boolean
clubCardText	string
cooperative	boolean
}
PaymentMethods{
cash	boolean
creditCard	boolean
debitCard	boolean
others	string
}
Price{
amount	number
fuelType*	string
label	string
}
Region{
cities	[string]
code	integer($int64)
name	string
postalCodes	[string]
subRegions	[{...}]
type	stringEnum:
[ PB, BL ]
}
Online validator badge
