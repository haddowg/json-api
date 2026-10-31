# A parameter that needs a negotiated profile carries `x-profile`

A query parameter recognised only under a negotiated profile (such as `?withCount`) carries the profile URI as an `x-profile` vendor extension, so a generated client knows to send `Accept: …; profile="<uri>"` instead of meeting a `400`. Prose in the description alone is not machine-readable. The projector emits whatever profile the parameter belongs to, so an application-defined profile is covered without a central catalogue.
