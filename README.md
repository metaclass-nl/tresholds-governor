tresholds-governor
==================

Tresholds Governor, aims to facilitate the protection of authentication against brute force and dictionary attacks

INTRODUCTION
------------
The OWASP Guide states "Applications MUST protect credentials from common authentication attacks as detailed 
in the Testing Guide". This library aims to protect user credentials from  
these authentication attacks. It is based on the "Tresholds Governer" described in the OWASP Guide.

FEATURES
--------

- Framework-independent library to be used from Framework-specific bundle/component or from applications that
  implement their own authentication

- Registers authentication counts and decides to Block by username or client ip address for 
  which authentication failed too often,
 
- To hide weather an account actually exists for a username, any username that is tried too often may be blocked, 
  regardless of the existence and status of an account with that username,

- Facilitates a logical difference between failed login lockout and eventual administrative lockout, 
  so that re-enabling all usernames en masse does not unlock administratively locked users (OWASP requirement).

- Automatic release of username on authentication success (optional),

- Stores counters instead of individual requests to prevent database flooding from brute force attacks.

REQUIREMENTS
------------
PHP >=7.1,

PDO or Doctrine DBAL ^2.3.4 or custom implementations of the Management interfaces.

Tested with MySQL 5.5. and SQLite 3

RELEASE NOTES
-------------

This is a pre-release version under development. 

May be vurnerable to enumeration of usernames through timing attacks because of
differences in database query performance for frequently and infrequently used usernames.
This can be mitigated by calling ::sleepUntilFixedExecutionTime. Under normal circomstances
that should be sufficient if the fixedExecutionSeconds is set long enough, but under
high (database) server loads when performance degrades, under specific conditons
information may still be extractable by timing.

DOCUMENTATION
-------------
- [Installation and configuration](doc/Installation.md)
- [Counting and deciding](doc/Counting and deciding.md)
	
SUPPORT
-------

MetaClass offers help and support on a commercial basis with 
the application and extension of this bundle and additional 
security measures.

http://www.metaclass.nl/site/index_php/Menu/10/Contact.html


COPYRIGHT AND LICENCE
---------------------

Unless notified otherwise Copyright (c) 2014 MetaClass Groningen. All rights reserved.

This bundle is under the MIT license. See the complete license in the bundle:

	meta/LICENSE

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.