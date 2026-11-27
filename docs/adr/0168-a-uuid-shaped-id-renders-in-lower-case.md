# A uuid-shaped id renders in lower case

A resource's own id rendered its key as stored, while linkage read from a column renders a
uuid-shaped id in lower case, so a guid kept in capitals had two wire ids. The `Id` field now
renders a uuid-shaped key with no encoder in lower case, and a foreign key's parent key is
read off the parent as stored, so writes keep storing the key in the case its row keeps.
